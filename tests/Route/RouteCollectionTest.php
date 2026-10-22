<?php

namespace Themosis\Tests\Route;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use PHPUnit\Framework\TestCase;
use Themosis\Route\Router;

class RouteCollectionTest extends TestCase
{
    public function test_routes_with_same_uri_and_overlapping_methods_are_all_kept()
    {
        $router = $this->getRouter();

        $router->get('foo', function () {
            return 'get';
        });
        $router->match(['POST', 'GET'], 'foo', function () {
            return 'post-get';
        });

        $this->assertCount(2, $router->getRoutes()->getRoutes());
    }

    public function test_routes_can_be_serialized_and_restored()
    {
        $router = $this->getRouter();

        $router->get('foo/{bar}', function ($bar) {
            return 'foo '.$bar;
        })->name('foo');
        $router->get('home', function () {
            return 'WordPress home';
        });

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        foreach ($routes as $route) {
            $route->prepareForSerialization();
        }

        $restored = unserialize(serialize($routes));

        $router = $this->getRouter();
        $router->setRoutes($restored);

        $this->assertSame('WordPress home', $router->dispatch(Request::create('/', 'GET'))->getContent());
        $this->assertSame('foo baz', $router->dispatch(Request::create('foo/baz', 'GET'))->getContent());
        $this->assertTrue($router->getRoutes()->hasNamedRoute('foo'));
    }

    protected function getRouter()
    {
        $container = new Container;
        $router = new Router(new Dispatcher, $container);
        $container->singleton(Registrar::class, function () use ($router) {
            return $router;
        });
        $container->singleton(CallableDispatcherContract::class, function ($app) {
            return new CallableDispatcher($app);
        });
        $router->setConditions([
            'is_home' => ['home', 'blog'],
        ]);

        return $router;
    }
}
