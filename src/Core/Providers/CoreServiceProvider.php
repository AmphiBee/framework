<?php

namespace Themosis\Core\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\AggregateServiceProvider;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Uri;
use Illuminate\Validation\ValidationException;
use Themosis\Core\Precognition;

class CoreServiceProvider extends AggregateServiceProvider
{
    /**
     * The provider class names.
     *
     * @var array
     */
    protected $providers = [
        FormRequestServiceProvider::class,
    ];

    /**
     * Register the service provider.
     */
    public function register()
    {
        parent::register();

        $this->registerRequestValidate();
        $this->registerRequestSignatureValidation();
        $this->registerUriUrlGeneration();
        $this->registerDeferHandler();
    }

    /**
     * Register the "validate" macro on the request.
     */
    public function registerRequestValidate()
    {
        Request::macro('validate', function (array $rules, ...$params) {
            return tap(validator($this->all(), $rules, ...$params), function ($validator) {
                if ($this->isPrecognitive()) {
                    $validator->after(Precognition::afterValidationHook($this))
                        ->setRules(
                            $this->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders()),
                        );
                }
            })->validate();
        });

        Request::macro('validateWithBag', function (string $errorBag, array $rules, ...$params) {
            try {
                return $this->validate($rules, ...$params);
            } catch (ValidationException $e) {
                $e->errorBag = $errorBag;

                throw $e;
            }
        });
    }

    /**
     * Register the "hasValidSignature" macro on the request.
     */
    public function registerRequestSignatureValidation()
    {
        Request::macro('hasValidSignature', function ($absolute = true) {
            return URL::hasValidSignature($this, $absolute);
        });

        Request::macro('hasValidRelativeSignature', function () {
            return URL::hasValidSignature($this, $absolute = false);
        });

        Request::macro('hasValidSignatureWhileIgnoring', function ($ignoreQuery = [], $absolute = true) {
            return URL::hasValidSignature($this, $absolute, $ignoreQuery);
        });

        Request::macro('hasValidRelativeSignatureWhileIgnoring', function ($ignoreQuery = []) {
            return URL::hasValidSignature($this, $absolute = false, $ignoreQuery);
        });
    }

    /**
     * Register the URL resolver for the URI generator.
     */
    protected function registerUriUrlGeneration()
    {
        Uri::setUrlGeneratorResolver(fn () => app('url'));
    }

    /**
     * Register the "defer" function termination handler.
     */
    protected function registerDeferHandler()
    {
        $this->app->scoped(DeferredCallbackCollection::class);

        $this->app['events']->listen(function (CommandFinished $event) {
            app(DeferredCallbackCollection::class)->invokeWhen(fn ($callback) => app()->runningInConsole() && ($event->exitCode === 0 || $callback->always));
        });

        $this->app['events']->listen(function (JobAttempted $event) {
            if (in_array($event->connectionName, ['sync', 'deferred'])) {
                return;
            }

            app(DeferredCallbackCollection::class)->invokeWhen(fn ($callback) => ($event->successful() || $callback->always));
        });
    }

    /**
     * Publish core assets.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../../dist' => web_path('dist'),
            ], 'themosis');

            $this->publishes([
                __DIR__.'/../Exceptions/views' => resource_path('views/errors/'),
            ], 'themosis-errors');

            $this->publishes([
                __DIR__.'/../Dropins/files' => content_path(),
            ], 'themosis-dropins');

            $this->publishes([
                __DIR__.'/../Dropins/files/object-cache.php' => content_path('object-cache.php'),
            ], 'themosis-dropins-objectcache');
        }
    }
}
