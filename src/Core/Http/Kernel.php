<?php

namespace Themosis\Core\Http;

use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\InteractsWithTime;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Themosis\Core\Bootstrap\BootProviders;
use Themosis\Core\Bootstrap\ConfigurationLoader;
use Themosis\Core\Bootstrap\EnvironmentLoader;
use Themosis\Core\Bootstrap\RegisterFacades;
use Themosis\Core\Bootstrap\RegisterProviders;
use Themosis\Core\Http\Events\RequestHandled;
use Themosis\Core\Http\Middleware\HandlePrecognitiveRequests;
use Themosis\Core\Http\Middleware\InvokeDeferredCallbacks;
use Themosis\Route\Router;
use Throwable;

class Kernel implements \Illuminate\Contracts\Http\Kernel
{
    use InteractsWithTime;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Router
     */
    protected $router;

    /**
     * List of bootstrap classes.
     *
     * @var array
     */
    protected $bootstrappers = [
        EnvironmentLoader::class,
        ConfigurationLoader::class,
        \Themosis\Core\Bootstrap\ExceptionHandler::class,
        RegisterFacades::class,
        RegisterProviders::class,
        BootProviders::class,
    ];

    /**
     * Application middleware stack. Used on every request.
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [];

    /**
     * Aliased middleware. Can be used individually or within groups.
     *
     * @var array
     *
     * @deprecated Use $middlewareAliases instead.
     */
    protected $routeMiddleware = [];

    /**
     * The application's middleware aliases.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [];

    /**
     * All of the registered request duration handlers.
     *
     * @var array
     */
    protected $requestLifecycleDurationHandlers = [];

    /**
     * When the kernel starting handling the current request.
     *
     * @var Carbon|null
     */
    protected $requestStartedAt;

    /**
     * Priority-sorted list of middleware.
     *
     * @var array
     */
    protected $middlewarePriority = [
        HandlePrecognitiveRequests::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        AuthenticatesRequests::class,
        ThrottleRequests::class,
        ThrottleRequestsWithRedis::class,
        AuthenticatesSessions::class,
        SubstituteBindings::class,
        Authorize::class,
    ];

    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;

        // Laravel 11+ invokes the callbacks registered with the "defer()" helper
        // once the response has been sent, through this global middleware.
        $this->prependMiddleware(InvokeDeferredCallbacks::class);

        $this->syncMiddlewareToRouter();
    }

    /**
     * Initialize the kernel (bootstrap application base components).
     *
     * @param  Request  $request
     */
    public function init($request)
    {
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        $this->bootstrap();
    }

    /**
     * Handle an incoming HTTP request.
     *
     * @param  Request  $request
     * @return IlluminateResponse
     */
    public function handle($request)
    {
        $this->requestStartedAt = Carbon::now();

        try {
            $request->enableHttpMethodParameterOverride();
            $response = $this->sendRequestThroughRouter($request);
        } catch (Throwable $e) {
            $this->reportException($e);
            $response = $this->renderException($request, $e);
        }

        $this->app['events']->dispatch(
            new RequestHandled($request, $response),
        );

        return $response;
    }

    /**
     * Determine if the kernel has a given middleware.
     *
     * @param  string  $middleware
     * @return bool
     */
    public function hasMiddleware($middleware)
    {
        return in_array($middleware, $this->middleware);
    }

    /**
     * Send given request through the middleware (if any) and router.
     *
     * @param  Request  $request
     * @return IlluminateResponse
     */
    protected function sendRequestThroughRouter($request)
    {
        return (new Pipeline($this->app))
            ->send($request)
            ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
            ->then($this->dispatchToRouter());
    }

    /**
     * Get route dispatcher callback used by the
     * routing pipeline.
     *
     * @return \Closure
     */
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }

    /**
     * Bootstrap the application.
     */
    public function bootstrap()
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }
    }

    /**
     * Return the bootstrappers array.
     *
     * @return array
     */
    protected function bootstrappers()
    {
        return $this->bootstrappers;
    }

    /**
     * Call the terminate method on any terminable middleware.
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @param  Response  $response
     */
    public function terminate($request, $response)
    {
        $this->terminateMiddleware($request, $response);
        $this->app->terminate();

        if ($this->requestStartedAt === null) {
            return;
        }

        $this->requestStartedAt->setTimezone($this->app['config']->get('app.timezone') ?? 'UTC');

        foreach ($this->requestLifecycleDurationHandlers as ['threshold' => $threshold, 'handler' => $handler]) {
            $end ??= Carbon::now();

            if ($this->requestStartedAt->diffInMilliseconds($end) > $threshold) {
                $handler($this->requestStartedAt, $request, $response);
            }
        }

        $this->requestStartedAt = null;
    }

    /**
     * Register a callback to be invoked when the requests lifecycle duration exceeds a given amount of time.
     *
     * @param  DateTimeInterface|CarbonInterval|float|int  $threshold
     * @param  callable  $handler
     * @return void
     */
    public function whenRequestLifecycleIsLongerThan($threshold, $handler)
    {
        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        $this->requestLifecycleDurationHandlers[] = [
            'threshold' => $threshold,
            'handler' => $handler,
        ];
    }

    /**
     * When the request being handled started.
     *
     * @return Carbon|null
     */
    public function requestStartedAt()
    {
        return $this->requestStartedAt;
    }

    /**
     * Call the terminate method on any terminable middleware.
     *
     * @param  Request  $request
     * @param  IlluminateResponse  $response
     */
    protected function terminateMiddleware($request, $response)
    {
        $middlewares = $this->app->shouldSkipMiddleware() ? [] : array_merge(
            $this->gatherRouteMiddleware($request),
            $this->middleware,
        );

        foreach ($middlewares as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name] = $this->parseMiddleware($middleware);

            $instance = $this->app->make($name);

            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }
    }

    /**
     * Gather the route middleware for the given request.
     *
     * @param  Request  $request
     * @return array
     */
    protected function gatherRouteMiddleware($request)
    {
        if ($route = $request->route()) {
            return $this->router->gatherRouteMiddleware($route);
        }

        return [];
    }

    /**
     * Parse a middleware string to get the name and parameters.
     *
     * @param  string  $middleware
     * @return array
     */
    protected function parseMiddleware($middleware)
    {
        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, []);

        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }

        return [$name, $parameters];
    }

    /**
     * Add a new middleware to beginning of the stack if it does not already exist.
     *
     * @param  string  $middleware
     * @return $this
     */
    public function prependMiddleware($middleware)
    {
        if (array_search($middleware, $this->middleware) === false) {
            array_unshift($this->middleware, $middleware);
        }

        return $this;
    }

    /**
     * Add a new middleware to end of the stack if it does not already exist.
     *
     * @param  string  $middleware
     * @return $this
     */
    public function pushMiddleware($middleware)
    {
        if (array_search($middleware, $this->middleware) === false) {
            $this->middleware[] = $middleware;
        }

        return $this;
    }

    /**
     * Prepend the given middleware to the given middleware group.
     *
     * @param  string  $group
     * @param  string  $middleware
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function prependMiddlewareToGroup($group, $middleware)
    {
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            array_unshift($this->middlewareGroups[$group], $middleware);
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Append the given middleware to the given middleware group.
     *
     * @param  string  $group
     * @param  string  $middleware
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function appendMiddlewareToGroup($group, $middleware)
    {
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            $this->middlewareGroups[$group][] = $middleware;
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Prepend the given middleware to the middleware priority list.
     *
     * @param  string  $middleware
     * @return $this
     */
    public function prependToMiddlewarePriority($middleware)
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            array_unshift($this->middlewarePriority, $middleware);
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Append the given middleware to the middleware priority list.
     *
     * @param  string  $middleware
     * @return $this
     */
    public function appendToMiddlewarePriority($middleware)
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            $this->middlewarePriority[] = $middleware;
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Add the given middleware to the middleware priority list before other middleware.
     *
     * @param  array|string  $before
     * @param  string  $middleware
     * @return $this
     */
    public function addToMiddlewarePriorityBefore($before, $middleware)
    {
        return $this->addToMiddlewarePriorityRelative($before, $middleware, after: false);
    }

    /**
     * Add the given middleware to the middleware priority list after other middleware.
     *
     * @param  array|string  $after
     * @param  string  $middleware
     * @return $this
     */
    public function addToMiddlewarePriorityAfter($after, $middleware)
    {
        return $this->addToMiddlewarePriorityRelative($after, $middleware);
    }

    /**
     * Add the given middleware to the middleware priority list relative to other middleware.
     *
     * @param  string|array  $existing
     * @param  string  $middleware
     * @param  bool  $after
     * @return $this
     */
    protected function addToMiddlewarePriorityRelative($existing, $middleware, $after = true)
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            $index = $after ? 0 : count($this->middlewarePriority);

            foreach ((array) $existing as $existingMiddleware) {
                if (in_array($existingMiddleware, $this->middlewarePriority)) {
                    $middlewareIndex = array_search($existingMiddleware, $this->middlewarePriority);

                    if ($after && $middlewareIndex > $index) {
                        $index = $middlewareIndex + 1;
                    } elseif ($after === false && $middlewareIndex < $index) {
                        $index = $middlewareIndex;
                    }
                }
            }

            if ($index === 0 && $after === false) {
                array_unshift($this->middlewarePriority, $middleware);
            } elseif (($after && $index === 0) || $index === count($this->middlewarePriority)) {
                $this->middlewarePriority[] = $middleware;
            } else {
                array_splice($this->middlewarePriority, $index, 0, $middleware);
            }
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Sync the current state of the middleware to the router.
     *
     * @return void
     */
    protected function syncMiddlewareToRouter()
    {
        $this->router->middlewarePriority = $this->middlewarePriority;

        foreach ($this->middlewareGroups as $key => $middleware) {
            $this->router->middlewareGroup($key, $middleware);
        }

        foreach (array_merge($this->routeMiddleware, $this->middlewareAliases) as $key => $middleware) {
            $this->router->aliasMiddleware($key, $middleware);
        }
    }

    /**
     * Get the priority-sorted list of middleware.
     *
     * @return array
     */
    public function getMiddlewarePriority()
    {
        return $this->middlewarePriority;
    }

    /**
     * Set the application's middleware priority.
     *
     * @return $this
     */
    public function setMiddlewarePriority(array $priority)
    {
        $this->middlewarePriority = $priority;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Get the application's global middleware.
     *
     * @return array
     */
    public function getGlobalMiddleware()
    {
        return $this->middleware;
    }

    /**
     * Set the application's global middleware.
     *
     * @return $this
     */
    public function setGlobalMiddleware(array $middleware)
    {
        $this->middleware = $middleware;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Return the application instance.
     *
     * @return Application
     */
    public function getApplication()
    {
        return $this->app;
    }

    /**
     * Set the application instance.
     *
     * @return $this
     */
    public function setApplication(Application $app)
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Get the application's route middleware groups.
     *
     * @return array
     */
    public function getMiddlewareGroups()
    {
        return $this->middlewareGroups;
    }

    /**
     * Set the application's middleware groups.
     *
     * @return $this
     */
    public function setMiddlewareGroups(array $groups)
    {
        $this->middlewareGroups = $groups;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Get the application's route middleware aliases.
     *
     * @return array
     *
     * @deprecated Use getMiddlewareAliases() instead.
     */
    public function getRouteMiddleware()
    {
        return $this->getMiddlewareAliases();
    }

    /**
     * Get the application's route middleware aliases.
     *
     * @return array
     */
    public function getMiddlewareAliases()
    {
        return array_merge($this->routeMiddleware, $this->middlewareAliases);
    }

    /**
     * Set the application's route middleware aliases.
     *
     * @return $this
     */
    public function setMiddlewareAliases(array $aliases)
    {
        $this->middlewareAliases = $aliases;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Report the exception to the exception handler.
     */
    protected function reportException(Throwable $e)
    {
        $this->app[ExceptionHandler::class]->report($e);
    }

    /**
     * Render the exception to a response.
     *
     * @param  Request  $request
     * @return Response
     */
    protected function renderException($request, Throwable $e)
    {
        return $this->app[ExceptionHandler::class]->render($request, $e);
    }
}
