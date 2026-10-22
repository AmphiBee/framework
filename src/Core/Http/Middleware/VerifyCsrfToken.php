<?php

namespace Themosis\Core\Http\Middleware;

use Illuminate\Container\EntryNotFoundException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\InteractsWithTime;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class VerifyCsrfToken
{
    use InteractsWithTime;

    /**
     * @var Application|\Themosis\Core\Application
     */
    protected $app;

    /**
     * @var Encrypter
     */
    protected $encrypter;

    /**
     * URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [];

    /**
     * The globally ignored URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected static $neverVerify = [];

    /**
     * Indicates wheter the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    public function __construct(Application $application, Encrypter $encrypter)
    {
        $this->app = $application;
        $this->encrypter = $encrypter;
    }

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @return mixed
     *
     * @throws TokenMismatchException
     */
    public function handle($request, \Closure $next)
    {
        if ($this->isReading($request) ||
            $this->runningUnitTests() ||
            $this->inExceptArray($request) ||
            $this->tokensMatch($request)
        ) {
            return tap($next($request), function ($response) use ($request) {
                if ($this->shouldAddXsrfTokenCookie()) {
                    $this->addCookieToResponse($request, $response);
                }
            });
        }

        throw new TokenMismatchException('CSRF token mismatch.');
    }

    /**
     * Determine if the HTTP request uses a "read" verb.
     *
     *
     * @return bool
     */
    protected function isReading(Request $request)
    {
        return in_array($request->method(), ['HEAD', 'GET', 'OPTIONS']);
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    protected function runningUnitTests()
    {
        return $this->app->runningInConsole() && $this->app->runningUnitTests();
    }

    /**
     * Check if the request has a URI that should pass through CSRF verification.
     *
     *
     * @return bool
     */
    protected function inExceptArray(Request $request)
    {
        foreach ($this->getExcludedPaths() as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->fullUrlIs($except) || $request->is($except)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the URIs that should be excluded from CSRF verification.
     *
     * @return array
     */
    public function getExcludedPaths()
    {
        return array_merge($this->except, static::$neverVerify);
    }

    /**
     * Check if the session and input CSRF tokens match.
     *
     *
     * @return bool
     */
    protected function tokensMatch(Request $request)
    {
        $token = $this->getTokenFromRequest($request);

        return is_string($request->session()->token()) &&
            is_string($token) &&
            hash_equals($request->session()->token(), $token);
    }

    /**
     * Return the CSRF token from request.
     *
     *
     * @return string|null
     */
    protected function getTokenFromRequest(Request $request)
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if (! $token && $header = $request->header('X-XSRF-TOKEN')) {
            try {
                $token = CookieValuePrefix::remove($this->encrypter->decrypt($header, static::serialized()));
            } catch (DecryptException) {
                $token = '';
            }
        }

        return $token;
    }

    /**
     * Determine if the cookie should be added to the response.
     *
     * @return bool
     */
    public function shouldAddXsrfTokenCookie()
    {
        return $this->addHttpCookie;
    }

    /**
     * Add the CSRF token to the response cookies.
     *
     *
     * @return Response
     *
     * @throws EntryNotFoundException
     */
    protected function addCookieToResponse(Request $request, Response $response)
    {
        $config = config('session');

        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        $response->headers->setCookie($this->newCookie($request, $config));

        return $response;
    }

    /**
     * Create a new "XSRF-TOKEN" cookie that contains the CSRF token.
     *
     * @param  Request  $request
     * @param  array  $config
     * @return Cookie
     */
    protected function newCookie($request, $config)
    {
        return new Cookie(
            'XSRF-TOKEN',
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false,
        );
    }

    /**
     * Indicate that the given URIs should be excluded from CSRF verification.
     *
     * @param  array|string  $uris
     * @return void
     */
    public static function except($uris)
    {
        static::$neverVerify = array_values(array_unique(
            array_merge(static::$neverVerify, Arr::wrap($uris)),
        ));
    }

    /**
     * Determine if the cookie contents should be serialized.
     *
     * @return bool
     */
    public static function serialized()
    {
        return EncryptCookies::serialized('XSRF-TOKEN');
    }

    /**
     * Flush the state of the middleware.
     *
     * @return void
     */
    public static function flushState()
    {
        static::$neverVerify = [];
    }
}
