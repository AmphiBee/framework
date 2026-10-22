<?php

namespace Themosis\Tests\Core\Http;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Http\Middleware\ConvertEmptyStringsToNull;
use Themosis\Core\Http\Middleware\TrimStrings;
use Themosis\Core\Http\Middleware\VerifyCsrfToken;

class MiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        TrimStrings::flushState();
        ConvertEmptyStringsToNull::flushState();
        VerifyCsrfToken::flushState();
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_trim_strings_skips_passwords_and_globally_excepted_attributes()
    {
        TrimStrings::except(['secret.*']);

        $request = Request::create('/', 'POST', [
            'name' => "  Themosis\u{200B} ",
            'password' => ' pass ',
            'secret' => ['key' => ' value '],
        ]);

        (new TrimStrings)->handle($request, fn ($request) => $request);

        $this->assertSame('Themosis', $request->input('name'));
        $this->assertSame(' pass ', $request->input('password'));
        $this->assertSame(' value ', $request->input('secret.key'));
    }

    public function test_trim_strings_and_convert_empty_strings_skip_callbacks_can_be_flushed()
    {
        TrimStrings::skipWhen(fn () => true);
        ConvertEmptyStringsToNull::skipWhen(fn () => true);

        TrimStrings::flushState();
        ConvertEmptyStringsToNull::flushState();

        $request = Request::create('/', 'POST', ['name' => '  ', 'other' => '']);

        (new TrimStrings)->handle($request, fn ($request) => $request);
        (new ConvertEmptyStringsToNull)->handle($request, fn ($request) => $request);

        $this->assertNull($request->input('name'));
        $this->assertNull($request->input('other'));
    }

    public function test_csrf_token_is_read_from_the_xsrf_header()
    {
        [$middleware, $request, $encrypter] = $this->getCsrfContext();

        $request->headers->set('X-XSRF-TOKEN', $encrypter->encrypt(
            CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).$request->session()->token(),
            VerifyCsrfToken::serialized(),
        ));

        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
        $this->assertSame('XSRF-TOKEN', $response->headers->getCookies()[0]->getName());
    }

    public function test_csrf_verification_fails_without_token()
    {
        [$middleware, $request] = $this->getCsrfContext();

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function test_csrf_verification_can_be_skipped_globally()
    {
        [$middleware, $request] = $this->getCsrfContext();

        VerifyCsrfToken::except('webhooks/*');

        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    protected function getCsrfContext(): array
    {
        $app = new class extends Application
        {
            public function runningUnitTests()
            {
                return false;
            }
        };

        $app->instance('config', new Repository([
            'session' => [
                'lifetime' => 120,
                'path' => '/',
                'domain' => null,
                'secure' => false,
                'same_site' => 'lax',
            ],
        ]));
        Container::setInstance($app);

        $encrypter = new Encrypter(str_repeat('a', 32), 'AES-256-CBC');

        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();

        $request = Request::create('/webhooks/stripe', 'POST');
        $request->setLaravelSession($session);

        return [new VerifyCsrfToken($app, $encrypter), $request, $encrypter];
    }
}
