<?php

namespace Themosis\Tests\Core\Http;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Http\Kernel;
use Themosis\Core\Http\Middleware\InvokeDeferredCallbacks;
use Themosis\Route\Router;

class KernelTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_middleware_aliases_and_groups_are_synced_to_the_router()
    {
        [$kernel, $router] = $this->getKernel(KernelTestKernelStub::class);

        $this->assertSame([
            'legacy' => 'LegacyMiddleware',
            'alias' => 'AliasMiddleware',
        ], $router->getMiddleware());
        $this->assertSame(['web' => ['FooMiddleware']], $router->getMiddlewareGroups());
        $this->assertSame($kernel->getMiddlewareAliases(), $kernel->getRouteMiddleware());

        $kernel->appendMiddlewareToGroup('web', 'BarMiddleware');
        $kernel->prependMiddlewareToGroup('web', 'BazMiddleware');

        $this->assertSame(['web' => ['BazMiddleware', 'FooMiddleware', 'BarMiddleware']], $router->getMiddlewareGroups());
    }

    public function test_middleware_priority_can_be_altered()
    {
        [$kernel, $router] = $this->getKernel();

        $kernel->setMiddlewarePriority(['Start', 'First', 'Last']);
        $kernel->addToMiddlewarePriorityAfter('First', 'Second');
        $kernel->addToMiddlewarePriorityBefore('First', 'Zero');
        $kernel->appendToMiddlewarePriority('Final');
        $kernel->prependToMiddlewarePriority('Initial');

        $this->assertSame(['Initial', 'Start', 'Zero', 'First', 'Second', 'Last', 'Final'], $kernel->getMiddlewarePriority());
        $this->assertSame($kernel->getMiddlewarePriority(), $router->middlewarePriority);
    }

    public function test_invoke_deferred_callbacks_is_a_global_middleware()
    {
        [$kernel] = $this->getKernel();

        $this->assertTrue($kernel->hasMiddleware(InvokeDeferredCallbacks::class));
        $this->assertSame([InvokeDeferredCallbacks::class], $kernel->getGlobalMiddleware());
    }

    public function test_deferred_callbacks_are_invoked_on_terminate()
    {
        [$kernel, , $app] = $this->getKernel();
        $app->scoped(DeferredCallbackCollection::class);

        $called = [];
        \Illuminate\Support\defer(function () use (&$called) {
            $called[] = 'deferred';
        });

        $kernel->terminate(Request::create('/'), new Response('OK', 200));

        $this->assertSame(['deferred'], $called);
        $this->assertCount(0, $app->make(DeferredCallbackCollection::class));
    }

    public function test_only_always_deferred_callbacks_are_invoked_on_failed_responses()
    {
        [$kernel, , $app] = $this->getKernel();
        $app->scoped(DeferredCallbackCollection::class);

        $called = [];
        \Illuminate\Support\defer(function () use (&$called) {
            $called[] = 'deferred';
        });
        \Illuminate\Support\defer(function () use (&$called) {
            $called[] = 'always';
        }, always: true);

        $kernel->terminate(Request::create('/'), new Response('Error', 500));

        $this->assertSame(['always'], $called);
    }

    public function test_request_lifecycle_duration_handlers_are_called()
    {
        [$kernel, $router] = $this->getKernel();

        $router->get('/', fn () => 'OK');

        $called = false;
        $kernel->whenRequestLifecycleIsLongerThan(-1, function ($startedAt, $request, $response) use (&$called) {
            $called = true;
        });

        $request = Request::create('/');
        $response = $kernel->handle($request);

        $this->assertNotNull($kernel->requestStartedAt());

        $kernel->terminate($request, $response);

        $this->assertTrue($called);
        $this->assertNull($kernel->requestStartedAt());
    }

    /**
     * @param  class-string<Kernel>  $kernelClass
     * @return array{0: Kernel, 1: Router, 2: Application}
     */
    protected function getKernel(string $kernelClass = Kernel::class): array
    {
        $app = new Application;
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));

        $router = new Router(new Dispatcher($app), $app);
        $app->instance('router', $router);

        return [new $kernelClass($app, $router), $router, $app];
    }
}

class KernelTestKernelStub extends Kernel
{
    protected $middlewareGroups = [
        'web' => ['FooMiddleware'],
    ];

    protected $routeMiddleware = [
        'legacy' => 'LegacyMiddleware',
    ];

    protected $middlewareAliases = [
        'alias' => 'AliasMiddleware',
    ];
}
