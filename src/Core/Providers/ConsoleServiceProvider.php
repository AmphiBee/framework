<?php

namespace Themosis\Core\Providers;

use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Routing\Console\ControllerMakeCommand;
use Illuminate\Routing\Console\MiddlewareMakeCommand;
use Illuminate\Support\ServiceProvider;
use Themosis\Core\Console\ConsoleMakeCommand;
use Themosis\Core\Console\DownCommand;
use Themosis\Core\Console\FormMakeCommand;
use Themosis\Core\Console\HookMakeCommand;
use Themosis\Core\Console\ModelMakeCommand;
use Themosis\Core\Console\ProviderMakeCommand;
use Themosis\Core\Console\RouteCacheCommand;
use Themosis\Core\Console\RouteClearCommand;
use Themosis\Core\Console\RouteListCommand;
use Themosis\Core\Console\UpCommand;
use Themosis\Core\Console\VendorPublishCommand;
use Themosis\Core\Console\ViewClearCommand;

class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Defer the loading of the provider.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Commands to register.
     *
     * @var array
     */
    protected $commands = [
        'Down' => 'command.down',
        'Up' => 'command.up',
        'RouteCache' => 'command.route.cache',
        'RouteClear' => 'command.route.clear',
        'RouteList' => 'command.route.list',
        'ViewClear' => 'command.view.clear'
    ];

    /**
     * Development commands to register.
     *
     * @var array
     */
    protected $devCommands = [
        'ConsoleMake' => 'command.console.make',
        'ControllerMake' => 'command.controller.make',
        'FactoryMake' => 'command.factory.make',
        'FormMake' => 'command.form.make',
        'HookMake' => 'command.hook.make',
        'MiddlewareMake' => 'command.middleware.make',
        'MigrateMake' => 'command.migrate.make',
        'ModelMake' => 'command.model.make',
        'ProviderMake' => 'command.provider.make',
        'VendorPublish' => 'command.vendor.publish'
    ];

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->registerCommands(array_merge(
            $this->commands,
            $this->devCommands
        ));
    }

    /**
     * Register the given commands.
     *
     * @param array $commands
     */
    protected function registerCommands(array $commands)
    {
        foreach (array_keys($commands) as $command) {
            call_user_func_array([$this, "register{$command}Command"], [$commands[$command]]);
        }

        $this->commands(array_values($commands));
    }

    /**
     * Register the make:command command.
     *
     * @param string $abstract
     */
    protected function registerConsoleMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new ConsoleMakeCommand($app['files']);
        });
    }

    /**
     * Register the make:controller command.
     *
     * @param string $abstract
     */
    protected function registerControllerMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new ControllerMakeCommand($app['files']);
        });
    }

    /**
     * Register the down command.
     *
     * @param string $abstract
     */
    protected function registerDownCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new DownCommand();
        });
    }

    /**
     * Register the make:factory command.
     *
     * @param string $abstract
     */
    protected function registerFactoryMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new FactoryMakeCommand($app['files']);
        });
    }

    /**
     * Register the make:form command.
     *
     * @param string $abstract
     */
    protected function registerFormMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new FormMakeCommand($app['files']);
        });
    }

    /**
     * Register the make:hook command.
     *
     * @param string $abstract
     */
    protected function registerHookMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new HookMakeCommand($app['files']);
        });
    }

    /**
     * Register the make:middleware command.
     *
     * @param string $abstract
     */
    protected function registerMiddlewareMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new MiddlewareMakeCommand($app['files']);
        });
    }

    /**
     * Register the make:migration command.
     *
     * @param string $abstract
     */
    protected function registerMigrateMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            // Once we have the migration creator registered, we will create the command
            // and inject the creator. The creator is responsible for the actual file
            // creation of the migrations, and may be extended by these developers.
            $creator = $app['migration.creator'];
            $composer = $app['composer'];

            return new MigrateMakeCommand($creator, $composer);
        });
    }

    /**
     * Register the make:model command.
     *
     * @param string $abstract
     */
    protected function registerModelMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new ModelMakeCommand($app['files']);
        });
    }

    /**
     * Register the make:provider command.
     *
     * @param string $abstract
     */
    protected function registerProviderMakeCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new ProviderMakeCommand($app['files']);
        });
    }

    /**
     * Register the route:cache command.
     *
     * @param string $abstract
     */
    protected function registerRouteCacheCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new RouteCacheCommand($app['files']);
        });
    }

    /**
     * Register the route:clear command.
     *
     * @param string $abstract
     */
    protected function registerRouteClearCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new RouteClearCommand($app['files']);
        });
    }

    /**
     * Register the route:list command.
     *
     * @param string $abstract
     */
    protected function registerRouteListCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new RouteListCommand($app['router']);
        });
    }

    /**
     * Register the up command.
     *
     * @param string $abstract
     */
    public function registerUpCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new UpCommand();
        });
    }

    /**
     * Register the vendor:publish command.
     *
     * @param string $abstract
     */
    protected function registerVendorPublishCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new VendorPublishCommand($app['files']);
        });
    }

    /**
     * Register the view:clear command.
     *
     * @param string $abstract
     */
    protected function registerViewClearCommand($abstract)
    {
        $this->app->singleton($abstract, function ($app) {
            return new ViewClearCommand($app['files']);
        });
    }

    /**
     * Return list of services provided.
     *
     * @return array
     */
    public function provides()
    {
        return array_merge(array_values($this->commands), array_values($this->devCommands));
    }
}
