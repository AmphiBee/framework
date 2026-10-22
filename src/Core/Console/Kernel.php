<?php

namespace Themosis\Core\Console;

use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Themosis\Core\Bootstrap\BootProviders;
use Themosis\Core\Bootstrap\ConfigurationLoader;
use Themosis\Core\Bootstrap\EnvironmentLoader;
use Themosis\Core\Bootstrap\RegisterFacades;
use Themosis\Core\Bootstrap\RegisterProviders;
use Themosis\Core\Bootstrap\SetRequestForConsole;
use Themosis\Core\Bus\PendingDispatch;
use Throwable;
use WeakMap;

class Kernel implements KernelContract
{
    use InteractsWithTime;

    /**
     * @var Application|\Themosis\Core\Application
     */
    protected $app;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * The Symfony event dispatcher implementation.
     *
     * @var EventDispatcherInterface|null
     */
    protected $symfonyDispatcher;

    /**
     * The console application instance.
     *
     * @var Artisan
     */
    protected $artisan;

    /**
     * @var array
     */
    protected $bootstrappers = [
        EnvironmentLoader::class,
        ConfigurationLoader::class,
        \Themosis\Core\Bootstrap\ExceptionHandler::class,
        RegisterFacades::class,
        SetRequestForConsole::class,
        RegisterProviders::class,
        BootProviders::class,
    ];

    /**
     * The console commands provided by the application.
     *
     * @var array
     */
    protected $commands = [];

    /**
     * Indicates if the Closure commands have been loaded.
     *
     * @var bool
     */
    protected $commandsLoaded = false;

    /**
     * The commands paths that have been "loaded".
     *
     * @var array
     */
    protected $loadedPaths = [];

    /**
     * All of the registered command duration handlers.
     *
     * @var array
     */
    protected $commandLifecycleDurationHandlers = [];

    /**
     * When the currently handled command started.
     *
     * @var Carbon|null
     */
    protected $commandStartedAt;

    public function __construct(Application $app, Dispatcher $events)
    {
        if (! defined('ARTISAN_BINARY')) {
            define('ARTISAN_BINARY', 'artisan');
        }

        $this->app = $app;
        $this->events = $events;

        $this->app->booted(function () {
            if (! $this->app->runningUnitTests()) {
                $this->rerouteSymfonyCommandEvents();
            }

            $this->defineConsoleSchedule();
        });
    }

    /**
     * Re-route the Symfony command events to their Laravel counterparts.
     *
     * @internal
     *
     * @return $this
     */
    public function rerouteSymfonyCommandEvents()
    {
        if (is_null($this->symfonyDispatcher)) {
            $this->symfonyDispatcher = new EventDispatcher;

            $this->symfonyDispatcher->addListener(ConsoleEvents::COMMAND, function (ConsoleCommandEvent $event) {
                $this->events->dispatch(
                    new CommandStarting($event->getCommand()?->getName() ?? '', $event->getInput(), $event->getOutput()),
                );
            });

            $this->symfonyDispatcher->addListener(ConsoleEvents::TERMINATE, function (ConsoleTerminateEvent $event) {
                $this->events->dispatch(
                    new CommandFinished($event->getCommand()?->getName() ?? '', $event->getInput(), $event->getOutput(), $event->getExitCode()),
                );
            });
        }

        return $this;
    }

    /**
     * Define the application's command schedule.
     *
     * Laravel registers this binding from its FoundationServiceProvider,
     * Themosis keeps registering it from the console kernel.
     */
    protected function defineConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function ($app) {
            return $this->resolveConsoleSchedule();
        });
    }

    /**
     * Resolve a console schedule instance.
     *
     * @return Schedule
     */
    public function resolveConsoleSchedule()
    {
        return tap(new Schedule($this->scheduleTimezone()), function ($schedule) {
            $this->schedule($schedule->useCache($this->scheduleCache()));
        });
    }

    /**
     * Get the name of the cache store that should manage scheduling mutexes.
     *
     * @return string|null
     */
    protected function scheduleCache()
    {
        return $this->app['config']->get('cache.schedule_store', Env::get('SCHEDULE_CACHE_DRIVER', function () {
            return Env::get('SCHEDULE_CACHE_STORE');
        }));
    }

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        //
    }

    /**
     * Get the timezone that should be used by default for scheduled events.
     *
     * @return \DateTimeZone|string|null
     */
    protected function scheduleTimezone()
    {
        $config = $this->app['config'];

        return $config->get('app.schedule_timezone', $config->get('app.timezone'));
    }

    /**
     * Run the console application.
     *
     * @param  InputInterface  $input
     * @param  null  $output
     * @return int
     */
    public function handle($input, $output = null)
    {
        $this->commandStartedAt = Carbon::now();

        try {
            $this->bootstrap();

            return $this->getArtisan()->run($input, $output);
        } catch (Throwable $e) {
            $this->reportException($e);
            $this->renderException($output, $e);

            return 1;
        }
    }

    /**
     * Terminate the application.
     *
     * @param  InputInterface  $input
     * @param  int  $status
     */
    public function terminate($input, $status)
    {
        $this->app->terminate();

        if ($this->commandStartedAt === null) {
            return;
        }

        $this->commandStartedAt->setTimezone($this->app['config']->get('app.timezone') ?? 'UTC');

        foreach ($this->commandLifecycleDurationHandlers as ['threshold' => $threshold, 'handler' => $handler]) {
            $end ??= Carbon::now();

            if ($this->commandStartedAt->diffInMilliseconds($end) > $threshold) {
                $handler($this->commandStartedAt, $input, $status);
            }
        }

        $this->commandStartedAt = null;
    }

    /**
     * Register a callback to be invoked when the command lifecycle duration exceeds a given amount of time.
     *
     * @param  DateTimeInterface|CarbonInterval|float|int  $threshold
     * @param  callable  $handler
     * @return void
     */
    public function whenCommandLifecycleIsLongerThan($threshold, $handler)
    {
        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        $this->commandLifecycleDurationHandlers[] = [
            'threshold' => $threshold,
            'handler' => $handler,
        ];
    }

    /**
     * When the command being handled started.
     *
     * @return Carbon|null
     */
    public function commandStartedAt()
    {
        return $this->commandStartedAt;
    }

    /**
     * Bootstrap the application for console commands.
     */
    public function bootstrap()
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }

        $this->app->loadDeferredProviders();

        if (! $this->commandsLoaded) {
            $this->commands();

            $this->commandsLoaded = true;
        }
    }

    /**
     * Register the Closure based commands for the application.
     */
    protected function commands()
    {
        //
    }

    /**
     * Register a closure based command with the application.
     *
     * @param  string  $signature
     * @return ClosureCommand
     */
    public function command($signature, \Closure $callback)
    {
        $command = new ClosureCommand($signature, $callback);

        Artisan::starting(function ($artisan) use ($command) {
            $artisan->add($command);
        });

        return $command;
    }

    /**
     * Register the given command with the console applicationb.
     *
     * @param  \Symfony\Component\Console\Command\Command  $command
     */
    public function registerCommand($command)
    {
        $this->getArtisan()->add($command);
    }

    /**
     * Get the bootstrap classes for the application.
     *
     * @return array
     */
    protected function bootstrappers()
    {
        return $this->bootstrappers;
    }

    /**
     * Register all of the commands in the given directory.
     *
     * @param  array|string  $paths
     *
     * @throws \ReflectionException
     */
    protected function load($paths)
    {
        $paths = array_unique(Arr::wrap($paths));

        $paths = array_filter($paths, function ($path) {
            return is_dir($path);
        });

        if (empty($paths)) {
            return;
        }

        $this->loadedPaths = array_values(
            array_unique(array_merge($this->loadedPaths, $paths)),
        );

        $namespace = $this->app->getNamespace();

        $possibleCommands = new WeakMap;

        $filterCommands = function (SplFileInfo $file) use ($namespace, &$possibleCommands) {
            $commandClassName = $this->commandClassFromFile($file, $namespace);

            $possibleCommands[$file] = $commandClassName;

            $command = rescue(fn () => new ReflectionClass($commandClassName), null, false);

            return $command instanceof ReflectionClass
                && $command->isSubClassOf(Command::class)
                && ! $command->isAbstract();
        };

        foreach ($this->findCommands($paths)->filter($filterCommands) as $file) {
            Artisan::starting(function ($artisan) use ($file, $possibleCommands) {
                $artisan->resolve($possibleCommands[$file]);
            });
        }
    }

    /**
     * Get the Finder instance for discovering command files.
     *
     * @return Finder
     */
    protected function findCommands(array $paths)
    {
        return Finder::create()->in($paths)->name('*.php')->files();
    }

    /**
     * Extract the command class name from the given file path.
     */
    protected function commandClassFromFile(SplFileInfo $file, string $namespace): string
    {
        return $namespace.str_replace(
            ['/', '.php'],
            ['\\', ''],
            Str::after($file->getRealPath(), realpath(app_path()).DIRECTORY_SEPARATOR),
        );
    }

    /**
     * Return the artisan application instance.
     *
     * @return Artisan
     */
    protected function getArtisan()
    {
        if (is_null($this->artisan)) {
            $artisan = new Artisan($this->app, $this->events, $this->app->version());
            $artisan->setName('Themosis Framework');

            // Commands using the #[AsCommand] attribute are only registered in the
            // command map and must be lazily resolved through the container loader.
            $this->artisan = $artisan->resolveCommands($this->commands)
                ->setContainerCommandLoader();

            if ($this->symfonyDispatcher instanceof EventDispatcher) {
                $this->artisan->setDispatcher($this->symfonyDispatcher);
                $this->artisan->setSignalsToDispatchEvent();
            }
        }

        return $this->artisan;
    }

    /**
     * Alias for "getArtisan()".
     */
    protected function getConsole()
    {
        return $this->getArtisan();
    }

    /**
     * Alias. Set the console application instance.
     *
     * @param  Artisan  $console
     */
    public function setConsole($console)
    {
        $this->setArtisan($console);
    }

    /**
     * Set the console application instance.
     *
     * @param  Artisan  $artisan
     */
    public function setArtisan($artisan)
    {
        $this->artisan = $artisan;
    }

    /**
     * Run a console command by name.
     *
     * @param  string  $command
     * @param  OutputInterface  $outputBuffer
     * @return int
     */
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $this->bootstrap();

        return $this->getArtisan()->call($command, $parameters, $outputBuffer);
    }

    /**
     * Queue the given console command.
     *
     * @param  string  $command
     * @return PendingDispatch
     */
    public function queue($command, array $parameters = [])
    {
        return QueueCommand::dispatch(func_get_args());
    }

    /**
     * Get all registered commands with the console.
     *
     * @return array
     */
    public function all()
    {
        $this->bootstrap();

        return $this->getArtisan()->all();
    }

    /**
     * Get the output for the last run command.
     *
     * @return string
     */
    public function output()
    {
        $this->bootstrap();

        return $this->getArtisan()->output();
    }

    /**
     * Report the exception to the exception handler.
     */
    protected function reportException(Throwable $e)
    {
        $this->app[ExceptionHandler::class]->report($e);
    }

    /**
     * Render the exception.
     *
     * @param  OutputInterface  $output
     */
    protected function renderException($output, Throwable $e)
    {
        $this->app[ExceptionHandler::class]->renderForConsole($output, $e);
    }
}
