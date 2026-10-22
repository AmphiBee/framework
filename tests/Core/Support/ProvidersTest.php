<?php

namespace Themosis\Tests\Core\Support;

use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Support\Providers\AuthServiceProvider;
use Themosis\Core\Support\Providers\EventServiceProvider;

class ProvidersTest extends TestCase
{
    protected $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application;
        $this->app->instance('files', new Filesystem);
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_event_listeners_are_registered_without_calling_boot()
    {
        $this->app->register(new class($this->app) extends EventServiceProvider
        {
            protected $listen = [
                'recette.event' => [ProvidersTestListener::class],
            ];

            public function boot()
            {
                // Applications are no longer required to call parent::boot().
            }
        });

        $this->app->boot();

        $this->assertTrue($this->app['events']->hasListeners('recette.event'));
    }

    public function test_event_discovery_is_disabled_for_application_providers()
    {
        $provider = new class($this->app) extends EventServiceProvider {};

        $this->assertFalse($provider->shouldDiscoverEvents());
    }

    public function test_policies_are_registered_when_booting()
    {
        $this->app->singleton(GateContract::class, function ($app) {
            return new Gate($app, fn () => null);
        });

        $this->app->register(new class($this->app) extends AuthServiceProvider
        {
            protected $policies = [
                ProvidersTestModel::class => ProvidersTestPolicy::class,
            ];
        });

        $this->assertNull($this->app[GateContract::class]->getPolicyFor(ProvidersTestModel::class));

        $this->app->boot();

        $this->assertInstanceOf(
            ProvidersTestPolicy::class,
            $this->app[GateContract::class]->getPolicyFor(ProvidersTestModel::class),
        );
    }
}

class ProvidersTestListener
{
    public function handle()
    {
        //
    }
}

class ProvidersTestModel {}

class ProvidersTestPolicy {}
