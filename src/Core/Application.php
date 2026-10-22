<?php

namespace Themosis\Core;

use Closure;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Container\EntryNotFoundException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Contracts\Mail\MailQueue;
use Illuminate\Contracts\Queue\Monitor;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Routing\BindingRegistrar;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Contracts\Session\Session;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;
use Illuminate\Log\Context\ContextServiceProvider;
use Illuminate\Log\LogManager;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Mail\Mailer;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Translation\Translator;
use Illuminate\View\Compilers\BladeCompiler;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Themosis\Ajax\Ajax;
use Themosis\Asset\Factory;
use Themosis\Core\Bootstrap\EnvironmentLoader;
use Themosis\Core\Events\LocaleUpdated;
use Themosis\Forms\FormFactory;
use Themosis\Hook\ActionBuilder;
use Themosis\Hook\FilterBuilder;
use Themosis\Html\HtmlBuilder;
use Themosis\Route\Router;
use Themosis\Route\RouteServiceProvider;
use Themosis\Taxonomy\TaxonomyFieldFactory;
use Twig\Environment;

class Application extends Container implements ApplicationContract, CachesConfiguration, CachesRoutes, HttpKernelInterface
{
    /**
     * Themosis framework version.
     */
    public const THEMOSIS_VERSION = '4.0.0';

    /**
     * Minimum Laravel (illuminate/*) version supported.
     * The installed version is returned by the version() method.
     *
     * @var string
     */
    public const VERSION = '12.68.0';

    /**
     * Application textdomain.
     */
    public const TEXTDOMAIN = 'themosis';

    /**
     * Base path of the framework.
     *
     * @var string
     */
    protected $basePath;

    /**
     * Path location (directory) of env files.
     *
     * @var string
     */
    protected $environmentPath;

    /**
     * Environment file name base.
     *
     * @var string
     */
    protected $environmentFile = '.env';

    /**
     * The deferred services and their providers.
     *
     * @var array
     */
    protected $deferredServices = [];

    /**
     * All of the registered service providers.
     *
     * @var array
     */
    protected $serviceProviders = [];

    /**
     * The array of registered callbacks.
     *
     * @var callable[]
     */
    protected $registeredCallbacks = [];

    /**
     * The names of the loaded service providers.
     *
     * @var array
     */
    protected $loadedProviders = [];

    /**
     * Indicates if the application has been bootstrapped or not.
     *
     * @var bool
     */
    protected $hasBeenBootstrapped = false;

    /**
     * Indicates if the application has booted.
     *
     * @var bool
     */
    protected $booted = false;

    /**
     * List of booting callbacks.
     *
     * @var array
     */
    protected $bootingCallbacks = [];

    /**
     * List of booted callbacks.
     *
     * @var array
     */
    protected $bootedCallbacks = [];

    /**
     * List of terminating callbacks.
     *
     * @var array
     */
    protected $terminatingCallbacks = [];

    /**
     * @var string
     */
    protected $namespace;

    protected $storagePath;

    public function __construct($basePath = null)
    {
        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();
        $this->registerBaseServiceProviders();
        $this->registerCoreContainerAliases();
    }

    /**
     * Get the version number of the application.
     *
     * @return string
     */
    public function version()
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('illuminate/support')) {
            $version = ltrim((string) InstalledVersions::getPrettyVersion('illuminate/support'), 'v');

            if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                return $version;
            }
        }

        return static::VERSION;
    }

    /**
     * Register basic bindings into the container.
     */
    protected function registerBaseBindings()
    {
        static::setInstance($this);
        $this->instance('app', $this);
        $this->instance(Container::class, $this);
        $this->instance(PackageManifest::class, new PackageManifest(
            new Filesystem,
            $this->basePath(),
            $this->getCachedPackagesPath(),
        ));
    }

    /**
     * Register base service providers.
     */
    protected function registerBaseServiceProviders()
    {
        $this->register(new EventServiceProvider($this));
        $this->register(new LogServiceProvider($this));
        $this->register(new ContextServiceProvider($this));
        $this->register(new RouteServiceProvider($this));
    }

    /**
     * Register the core class aliases in the container.
     */
    protected function registerCoreContainerAliases()
    {
        $list = [
            'action' => [
                ActionBuilder::class,
            ],
            'ajax' => [
                Ajax::class,
            ],
            'app' => [
                Application::class,
                \Illuminate\Contracts\Container\Container::class,
                ApplicationContract::class,
                ContainerInterface::class,
            ],
            'asset' => [
                Factory::class,
            ],
            'auth' => [
                AuthManager::class,
                \Illuminate\Contracts\Auth\Factory::class,
            ],
            'auth.driver' => [
                Guard::class,
            ],
            'auth.password' => [
                PasswordBrokerManager::class,
                PasswordBrokerFactory::class,
            ],
            'auth.password.broker' => [
                PasswordBroker::class,
                \Illuminate\Contracts\Auth\PasswordBroker::class,
            ],
            'blade.compiler' => [
                BladeCompiler::class,
            ],
            'cache' => [
                CacheManager::class,
                \Illuminate\Contracts\Cache\Factory::class,
            ],
            'cache.store' => [
                \Illuminate\Cache\Repository::class,
                \Illuminate\Contracts\Cache\Repository::class,
            ],
            'config' => [
                \Illuminate\Config\Repository::class,
                Repository::class,
            ],
            'cookie' => [
                CookieJar::class,
                \Illuminate\Contracts\Cookie\Factory::class,
                QueueingFactory::class,
            ],
            'db' => [
                ConnectionResolverInterface::class,
                DatabaseManager::class,
            ],
            'db.connection' => [
                Connection::class,
                ConnectionInterface::class,
            ],
            'encrypter' => [
                Encrypter::class,
                \Illuminate\Contracts\Encryption\Encrypter::class,
            ],
            'events' => [
                Dispatcher::class,
                \Illuminate\Contracts\Events\Dispatcher::class,
            ],
            'files' => [
                Filesystem::class,
            ],
            'filesystem' => [
                FilesystemManager::class,
                \Illuminate\Contracts\Filesystem\Factory::class,
            ],
            'filesystem.disk' => [
                \Illuminate\Contracts\Filesystem\Filesystem::class,
            ],
            'filesystem.cloud' => [
                Cloud::class,
            ],
            'filter' => [
                FilterBuilder::class,
            ],
            'form' => [
                FormFactory::class,
            ],
            'hash' => [
                HashManager::class,
            ],
            'hash.driver' => [
                Hasher::class,
            ],
            'html' => [
                HtmlBuilder::class,
            ],
            'log' => [
                LogManager::class,
                LoggerInterface::class,
            ],
            'mailer' => [
                Mailer::class,
                \Illuminate\Contracts\Mail\Mailer::class,
                MailQueue::class,
            ],
            'metabox' => [
                \Themosis\Metabox\Factory::class,
            ],
            'posttype' => [
                \Themosis\PostType\Factory::class,
            ],
            'queue' => [
                QueueManager::class,
                \Illuminate\Contracts\Queue\Factory::class,
                Monitor::class,
            ],
            'queue.connection' => [
                Queue::class,
            ],
            'queue.failer' => [
                FailedJobProviderInterface::class,
            ],
            'redirect' => [
                Redirector::class,
            ],
            'redis' => [
                RedisManager::class,
                \Illuminate\Contracts\Redis\Factory::class,
            ],
            'request' => [
                Request::class,
                SymfonyRequest::class,
            ],
            'router' => [
                Router::class,
                \Illuminate\Routing\Router::class,
                Registrar::class,
                BindingRegistrar::class,
            ],
            'session' => [
                SessionManager::class,
            ],
            'session.store' => [
                Store::class,
                Session::class,
            ],
            'taxonomy' => [
                \Themosis\Taxonomy\Factory::class,
            ],
            'taxonomy.field' => [
                TaxonomyFieldFactory::class,
            ],
            'translator' => [
                Translator::class,
                \Illuminate\Contracts\Translation\Translator::class,
            ],
            'twig' => [
                Environment::class,
            ],
            'url' => [
                UrlGenerator::class,
                \Illuminate\Contracts\Routing\UrlGenerator::class,
            ],
            'validator' => [
                \Illuminate\Validation\Factory::class,
                \Illuminate\Contracts\Validation\Factory::class,
            ],
            'view' => [
                \Illuminate\View\Factory::class,
                \Illuminate\Contracts\View\Factory::class,
            ],
        ];

        foreach ($list as $key => $aliases) {
            foreach ($aliases as $alias) {
                $this->alias($key, $alias);
            }
        }
    }

    /**
     * Get the base path of the Laravel installation.
     *
     * @param  string  $path  Optional path to append to the base path.
     * @return string
     */
    public function basePath($path = '')
    {
        return $this->basePath.($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Set the base path for the application.
     *
     * @param  string  $basePath
     * @return $this
     */
    public function setBasePath($basePath)
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->bindPathsInContainer();

        return $this;
    }

    /**
     * Bind all of the application paths in the container.
     */
    protected function bindPathsInContainer()
    {
        // Core
        $this->instance('path', $this->path());
        // Base
        $this->instance('path.base', $this->basePath());
        // Content
        $this->instance('path.content', $this->contentPath());
        // Mu-plugins
        $this->instance('path.muplugins', $this->mupluginsPath());
        // Plugins
        $this->instance('path.plugins', $this->pluginsPath());
        // Themes
        $this->instance('path.themes', $this->themesPath());
        // Application
        $this->instance('path.application', $this->applicationPath());
        // Resources
        $this->instance('path.resources', $this->resourcePath());
        // Languages
        $this->instance('path.lang', $this->langPath());
        // Web root
        $this->instance('path.web', $this->webPath());
        // Root
        $this->instance('path.root', $this->rootPath());
        // Config
        $this->instance('path.config', $this->configPath());
        // Public root
        $this->instance('path.public', $this->publicPath());
        // Storage
        $this->instance('path.storage', $this->storagePath());
        // Database
        $this->instance('path.database', $this->databasePath());
        // Bootstrap
        $this->instance('path.bootstrap', $this->bootstrapPath());
        // WordPress
        $this->instance('path.wp', $this->wordpressPath());
    }

    /**
     * Get the path to the application "themosis-application" directory.
     *
     * @param  string  $path
     * @return string
     */
    public function path($path = '')
    {
        return $this->basePath.DIRECTORY_SEPARATOR.'app'.($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the WordPress "content" directory.
     *
     * @param  string  $path
     * @return string
     */
    public function contentPath($path = '')
    {
        return WP_CONTENT_DIR.($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the WordPress "plugins" directory.
     *
     * @param  string  $path
     * @return string
     */
    public function pluginsPath($path = '')
    {
        return $this->contentPath('plugins').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the WordPress "mu-plugins" directory.
     *
     * @param  string  $path
     * @return string
     */
    public function mupluginsPath($path = '')
    {
        return $this->contentPath('mu-plugins').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the WordPress "themes" directory.
     *
     * @param  string  $path
     * @return string
     */
    public function themesPath($path = '')
    {
        return $this->contentPath('themes').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the application directory.
     *
     * @param  string  $path
     * @return string
     */
    public function applicationPath($path = '')
    {
        return $this->basePath('app').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the resources directory path.
     *
     * @param  string  $path
     * @return string
     */
    public function resourcePath($path = '')
    {
        return $this->basePath('resources').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the path to the resources "languages" directory.
     *
     * @param  string  $path
     * @return string
     */
    public function langPath($path = '')
    {
        return $this->resourcePath('languages').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the path of the web server root.
     *
     * @param  string  $path
     * @return string
     */
    public function webPath($path = '')
    {
        return $this->basePath(THEMOSIS_PUBLIC_DIR).($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the path to the public directory.
     *
     * @param  string  $path
     * @return string
     */
    public function publicPath($path = '')
    {
        return $this->webPath($path);
    }

    /**
     * Get the root path of the project.
     *
     * @param  string  $path
     * @return string
     */
    public function rootPath($path = '')
    {
        if (defined('THEMOSIS_ROOT')) {
            return THEMOSIS_ROOT.($path ? DIRECTORY_SEPARATOR.$path : $path);
        }

        return $this->webPath($path);
    }

    /**
     * Get the main application plugin configuration directory.
     *
     * @param  string  $path
     * @return string
     */
    public function configPath($path = '')
    {
        return $this->basePath('config').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the storage directory path.
     *
     * @param  string  $path
     * @return string
     */
    public function storagePath($path = '')
    {
        if (defined('THEMOSIS_ROOT')) {
            return $this->rootPath('storage').($path ? DIRECTORY_SEPARATOR.$path : $path);
        }

        return $this->contentPath('storage').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Set the storage directory.
     *
     * @param  string  $path
     * @return $this
     */
    public function useStoragePath($path)
    {
        $this->storagePath = $path;

        $this->instance('path.storage', $path);

        return $this;
    }

    /**
     * Get the database directory path.
     *
     * @param  string  $path
     * @return string
     */
    public function databasePath($path = '')
    {
        return $this->rootPath('database').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the bootstrap directory path.
     *
     * @param  string  $path
     * @return string
     */
    public function bootstrapPath($path = '')
    {
        return $this->rootPath('bootstrap').($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Get the WordPress directory path.
     *
     * @param  string  $path
     * @return string
     *
     * @throws EntryNotFoundException
     */
    public function wordpressPath($path = '')
    {
        return $this->webPath(env('WP_DIR', 'cms')).($path ? DIRECTORY_SEPARATOR.$path : $path);
    }

    /**
     * Set the environment file to be loaded during bootstrapping.
     *
     * @param  string  $file
     * @return $this
     */
    public function loadEnvironmentFrom($file)
    {
        $this->environmentFile = $file;

        return $this;
    }

    /**
     * Return the environment path directory.
     *
     * @return string
     */
    public function environmentPath()
    {
        return $this->environmentPath ?: $this->basePath();
    }

    /**
     * Set the directory for the environment file.
     *
     * @param  string  $path
     * @return $this
     */
    public function useEnvironmentPath($path)
    {
        $this->environmentPath = $path;

        return $this;
    }

    /**
     * Return the environment file name base.
     *
     * @return string
     */
    public function environmentFile()
    {
        return $this->environmentFile ?: '.env';
    }

    /**
     * Return the environment file path.
     *
     * @return string
     */
    public function environmentFilePath()
    {
        return $this->environmentPath().DIRECTORY_SEPARATOR.$this->environmentFile();
    }

    /**
     * Get or check the current application environment.
     *
     * @param  string|array  $environments
     * @return string|bool
     */
    public function environment(...$environments)
    {
        if (count($environments) > 0) {
            $patterns = is_array($environments[0]) ? $environments[0] : $environments;

            return Str::is($patterns, $this['env']);
        }

        return $this['env'];
    }

    /**
     * Detech application's current environment.
     *
     *
     * @return string
     */
    public function detectEnvironment(Closure $callback)
    {
        $args = $_SERVER['argv'] ?? null;

        return $this['env'] = (new EnvironmentDetector)->detect($callback, $args);
    }

    /**
     * Determine if we are running in the console.
     *
     * @return bool
     */
    public function runningInConsole()
    {
        return php_sapi_name() == 'cli' || php_sapi_name() == 'phpdbg';
    }

    /**
     * Determine if the application is currently down for maintenance.
     *
     * @return bool
     *
     * @throws EntryNotFoundException
     */
    public function isDownForMaintenance()
    {
        $filePath = $this->wordpressPath('.maintenance');

        if (function_exists('wp_installing') && ! file_exists($filePath)) {
            return \wp_installing();
        }

        return file_exists($filePath);
    }

    /**
     * Register all of the configured providers.
     */
    public function registerConfiguredProviders()
    {
        $providers = Collection::make($this->config['app.providers'])
            ->partition(function ($provider) {
                return Str::startsWith($provider, 'Illuminate\\');
            });

        $providers->splice(1, 0, [$this->make(PackageManifest::class)->providers()]);

        (new ProviderRepository($this, new Filesystem, $this->getCachedServicesPath()))
            ->load($providers->collapse()->toArray());

        $this->fireAppCallbacks($this->registeredCallbacks);
    }

    /**
     * Register a new registered listener.
     *
     * @param  callable  $callback
     * @return void
     */
    public function registered($callback)
    {
        $this->registeredCallbacks[] = $callback;
    }

    /**
     * Register a deferred provider and service.
     *
     * @param  string  $provider
     * @param  string|null  $service
     */
    public function registerDeferredProvider($provider, $service = null)
    {
        if ($service) {
            unset($this->deferredServices[$service]);
        }

        $this->register($instance = new $provider($this));

        if (! $this->booted) {
            $this->booting(function () use ($instance) {
                $this->bootProvider($instance);
            });
        }
    }

    /**
     * Add an array of services to the application's deferred services.
     */
    public function addDeferredServices(array $services)
    {
        $this->deferredServices = array_merge($this->deferredServices, $services);
    }

    /**
     * Get the application's deferred services.
     *
     * @return array
     */
    public function getDeferredServices()
    {
        return $this->deferredServices;
    }

    /**
     * Set the application's deferred services.
     */
    public function setDeferredServices(array $services)
    {
        $this->deferredServices = $services;
    }

    /**
     * Determine if the given service is a deferred service.
     *
     * @param  string  $service
     * @return bool
     */
    public function isDeferredService($service)
    {
        return isset($this->deferredServices[$service]);
    }

    /**
     * Remove an array of services from the application's deferred services.
     *
     * @return void
     */
    public function removeDeferredServices(array $services)
    {
        foreach ($services as $service) {
            unset($this->deferredServices[$service]);
        }
    }

    /**
     * Verify if the application has been bootstrapped before.
     *
     * @return bool
     */
    public function hasBeenBootstrapped()
    {
        return $this->hasBeenBootstrapped;
    }

    /**
     * Bootstrap the application with given list of bootstrap
     * classes.
     */
    public function bootstrapWith(array $bootstrappers)
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this['events']->dispatch('bootstrapping: '.$bootstrapper, [$this]);

            /*
             * Instantiate each bootstrap class and call its "bootstrap" method
             * with the Application as a parameter.
             */
            $this->make($bootstrapper)->bootstrap($this);

            $this['events']->dispatch('bootstrapped: '.$bootstrapper, [$this]);
        }
    }

    /**
     * Boot the application's service providers.
     */
    public function boot()
    {
        if ($this->booted) {
            return;
        }

        /*
         * Once the application has booted we will also fire some "booted" callbacks
         * for any listeners that need to do work after this initial booting gets
         * finished. This is useful when ordering the boot-up processes we run.
         */
        $this->fireAppCallbacks($this->bootingCallbacks);

        array_walk($this->serviceProviders, function ($provider) {
            $this->bootProvider($provider);
        });

        $this->booted = true;

        $this->fireAppCallbacks($this->bootedCallbacks);
    }

    /**
     * Call the booting callbacks for the application.
     */
    protected function fireAppCallbacks(array $callbacks)
    {
        foreach ($callbacks as $callback) {
            call_user_func($callback, $this);
        }
    }

    /**
     * Boot the given service provider.
     */
    protected function bootProvider(ServiceProvider $provider)
    {
        $provider->callBootingCallbacks();

        if (method_exists($provider, 'boot')) {
            $this->call([$provider, 'boot']);
        }

        $provider->callBootedCallbacks();
    }

    /**
     * Register a new boot listener.
     *
     * @param  mixed  $callback
     */
    public function booting($callback)
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a new "booted" listener.
     *
     * @param  mixed  $callback
     */
    public function booted($callback)
    {
        $this->bootedCallbacks[] = $callback;

        if ($this->isBooted()) {
            $this->fireAppCallbacks([$callback]);
        }
    }

    /**
     * Get the path to the cached services.php file.
     *
     * @return string
     */
    public function getCachedServicesPath()
    {
        return $this->normalizeCachePath('APP_SERVICES_CACHE', 'cache/services.php');
    }

    /**
     * Get the path to the cached packages.php file.
     *
     * @return string
     */
    public function getCachedPackagesPath()
    {
        return $this->normalizeCachePath('APP_PACKAGES_CACHE', 'cache/packages.php');
    }

    /**
     * Determine if the application configuration is cached.
     *
     * @return bool
     */
    public function configurationIsCached()
    {
        if ($this->bound('config_loaded_from_cache')) {
            return (bool) $this->make('config_loaded_from_cache');
        }

        return $this->instance('config_loaded_from_cache', is_file($this->getCachedConfigPath()));
    }

    /**
     * Get the path to the configuration cache file.
     *
     * @return string
     */
    public function getCachedConfigPath()
    {
        return $this->normalizeCachePath('APP_CONFIG_CACHE', 'cache/config.php');
    }

    /**
     * Handles a Request to convert it to a Response.
     *
     * When $catch is true, the implementation must catch all exceptions
     * and do its best to convert them to a Response instance.
     *
     * @param  SymfonyRequest  $request  A Request instance
     * @param  int  $type  The type of the request
     *                     (one of HttpKernelInterface::MASTER_REQUEST or HttpKernelInterface::SUB_REQUEST)
     * @param  bool  $catch  Whether to catch exceptions or not
     * @return Response A Response instance
     *
     * @throws \Exception When an Exception occurs during processing
     */
    public function handle(SymfonyRequest $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        return $this[HttpKernelContract::class]->handle(Request::createFromBase($request));
    }

    /**
     * Register a service provider with the application.
     *
     * @param  ServiceProvider|string  $provider
     * @param  array  $options
     * @param  bool  $force
     * @return ServiceProvider
     */
    public function register($provider, $options = [], $force = false)
    {
        if (($registered = $this->getProvider($provider)) && ! $force) {
            return $registered;
        }

        // If the given "provider" is a string, we will resolve it, passing in the
        // application instance automatically for the developer. This is simply
        // a more convenient way of specifying your service provider classes.
        if (is_string($provider)) {
            $provider = $this->resolveProvider($provider);
        }

        if (method_exists($provider, 'register')) {
            $provider->register();
        }

        // If there are bindings / singletons set as properties on the provider we
        // will spin through them and register them with the application, which
        // serves as a convenience layer while registering a lot of bindings.
        if (property_exists($provider, 'bindings')) {
            foreach ($provider->bindings as $key => $value) {
                $this->bind($key, $value);
            }
        }

        if (property_exists($provider, 'singletons')) {
            foreach ($provider->singletons as $key => $value) {
                $this->singleton($key, $value);
            }
        }

        $this->markAsRegistered($provider);

        // If the application has already booted, we will call this boot method on
        // the provider class so it has an opportunity to do its boot logic and
        // will be ready for any usage by this developer's application logic.
        if ($this->booted) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    /**
     * Get the registered service provider instance if it exists.
     *
     * @param  ServiceProvider|string  $provider
     * @return ServiceProvider|null
     */
    public function getProvider($provider)
    {
        return array_values($this->getProviders($provider))[0] ?? null;
    }

    /**
     * Get the registered service provider instances if any exist.
     *
     * @param  ServiceProvider|string  $provider
     * @return array
     */
    public function getProviders($provider)
    {
        $name = is_string($provider) ? $provider : get_class($provider);

        return Arr::where($this->serviceProviders, function ($value) use ($name) {
            return $value instanceof $name;
        });
    }

    /**
     * Get the service providers that have been loaded.
     *
     * @return array
     */
    public function getLoadedProviders()
    {
        return $this->loadedProviders;
    }

    /**
     * Determine if the given service provider is loaded.
     *
     *
     * @return bool
     */
    public function providerIsLoaded(string $provider)
    {
        return isset($this->loadedProviders[$provider]);
    }

    /**
     * Resolve a service provider instance from the class name.
     *
     * @param  string  $provider
     * @return ServiceProvider
     */
    public function resolveProvider($provider)
    {
        return new $provider($this);
    }

    /**
     * Mark the given provider as registered.
     *
     * @param  ServiceProvider  $provider
     */
    protected function markAsRegistered($provider)
    {
        $class = get_class($provider);

        $this->serviceProviders[$class] = $provider;
        $this->loadedProviders[$class] = true;
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    public function runningUnitTests()
    {
        return $this['env'] == 'testing';
    }

    /**
     * Determine if the application is running with debug mode enabled.
     *
     * @return bool
     */
    public function hasDebugModeEnabled()
    {
        return env('APP_DEBUG', false);
    }

    /**
     * Get an instance of the maintenance mode manager implementation.
     *
     * @return MaintenanceMode
     */
    public function maintenanceMode()
    {
        // @TODO to implement
        return false;
    }

    /**
     * Resolve the given type from the container.
     *
     * (Overriding Container::make)
     *
     * @param  string  $abstract
     * @return mixed
     *
     * @throws BindingResolutionException
     */
    public function make($abstract, array $parameters = [])
    {
        $this->loadDeferredProviderIfNeeded($abstract = $this->getAlias($abstract));

        return parent::make($abstract, $parameters);
    }

    /**
     * Resolve the given type from the container.
     *
     * Deferred providers are also loaded here so that dependencies
     * resolved by the container (constructor injection...) are found.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @param  bool  $raiseEvents
     * @return mixed
     */
    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        $this->loadDeferredProviderIfNeeded($abstract = $this->getAlias($abstract));

        return parent::resolve($abstract, $parameters, $raiseEvents);
    }

    /**
     * Load the deferred provider if the given type is a deferred service and the instance has not been loaded.
     *
     * @param  string  $abstract
     * @return void
     */
    protected function loadDeferredProviderIfNeeded($abstract)
    {
        if ($this->isDeferredService($abstract) && ! isset($this->instances[$abstract])) {
            $this->loadDeferredProvider($abstract);
        }
    }

    /**
     * Load and boot all of the remaining deferred providers.
     */
    public function loadDeferredProviders()
    {
        // We will simply spin through each of the deferred providers and register each
        // one and boot them if the application has booted. This should make each of
        // the remaining services available to this application for immediate use.
        foreach ($this->deferredServices as $service => $provider) {
            $this->loadDeferredProvider($service);
        }

        $this->deferredServices = [];
    }

    /**
     * Load the provider for a deferred service.
     *
     * @param  string  $service
     */
    public function loadDeferredProvider($service)
    {
        if (! isset($this->deferredServices[$service])) {
            return;
        }

        $provider = $this->deferredServices[$service];

        // If the service provider has not already been loaded and registered we can
        // register it with the application and remove the service from this list
        // of deferred services, since it will already be loaded on subsequent.
        if (! isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * (Overriding Container::bound)
     *
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return $this->isDeferredService($abstract) || parent::bound($abstract);
    }

    /**
     * Determine if the application has booted.
     *
     * @return bool
     */
    public function isBooted()
    {
        return $this->booted;
    }

    /**
     * Determine if middleware has been disabled for the application.
     *
     * @return bool
     */
    public function shouldSkipMiddleware()
    {
        return $this->bound('middleware.disable') &&
            $this->make('middleware.disable') === true;
    }

    /**
     * Bootstrap a Themosis like plugin.
     *
     *
     * @return PluginManager
     */
    public function loadPlugin(string $filePath, string $configPath)
    {
        $plugin = (new PluginManager($this, $filePath, new ClassLoader))->load($configPath);

        $this->instance('wp.plugin.'.$plugin->getHeader('plugin_id'), $plugin);

        return $plugin;
    }

    /**
     * Register the framework core "plugin" and auto-load
     * any found mu-plugins after the framework.
     *
     *
     * @throws FileNotFoundException
     * @throws \Exception
     */
    public function loadPlugins(string $pluginsPath)
    {
        $directories = Collection::make((new Filesystem)->directories($this->mupluginsPath()))
            ->map(function ($directory) {
                return ltrim(substr($directory, strrpos($directory, DS)), '\/');
            })->toArray();

        (new PluginsRepository($this, new Filesystem, $pluginsPath, $this->getCachedPluginsPath()))
            ->load($directories);
    }

    /**
     * Register a plugin and load it.
     *
     * @param  string  $path  Plugin relative path (pluginDirName/pluginMainFile).
     */
    public function registerPlugin(string $path)
    {
        require $this->mupluginsPath($path);
    }

    /**
     * Return cached plugins manifest file path.
     *
     * @return string
     */
    public function getCachedPluginsPath()
    {
        return $this->bootstrapPath('cache/plugins.php');
    }

    /**
     * Register a list of hookable instances.
     */
    public function registerConfiguredHooks(string $config = '')
    {
        if (empty($config)) {
            $config = 'app.hooks';
        }

        $hooks = Collection::make($this->config[$config]);

        (new HooksRepository($this))->load($hooks->all());
    }

    /**
     * Create and register a hook instance.
     */
    public function registerHook(string $hook)
    {
        // Build a "Hookable" instance.
        // Hookable instances must extend the "Hookable" class.
        $instance = new $hook($this);
        $hooks = (array) $instance->hook;

        if (! method_exists($instance, 'register')) {
            return;
        }

        if (! empty($hooks)) {
            $this['action']->add($hooks, [$instance, 'register'], $instance->priority);
        } else {
            $instance->register();
        }
    }

    /**
     * Load current active theme.
     *
     * @param  string  $dirPath  The theme directory path.
     * @param  string  $configPath  The theme relative configuration folder path.
     * @return ThemeManager
     */
    public function loadTheme(string $dirPath, string $configPath)
    {
        $theme = (new ThemeManager($this, $dirPath, new ClassLoader))
            ->load($dirPath.'/'.trim($configPath, '\/'));

        $this->instance('wp.theme', $theme);

        return $theme;
    }

    /**
     * Load configuration files based on given path.
     *
     * @param  string  $path  The configuration files folder path.
     * @return Application
     */
    public function loadConfigurationFiles(Repository $config, $path = '')
    {
        $files = $this->getConfigurationFiles($path);

        foreach ($files as $key => $path) {
            $config->set($key, require $path);
        }

        return $this;
    }

    /**
     * Get all configuration files.
     *
     * @param  mixed  $path
     * @return array
     */
    protected function getConfigurationFiles($path)
    {
        $files = [];

        foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
            $directory = $this->getNestedDirectory($file, $path);
            $files[$directory.basename($file->getRealPath(), '.php')] = $file->getRealPath();
        }

        ksort($files, SORT_NATURAL);

        return $files;
    }

    /**
     * Get configuration file nesting path.
     *
     * @param  string  $path
     * @return string
     */
    protected function getNestedDirectory(SplFileInfo $file, $path)
    {
        $directory = $file->getPath();

        if ($nested = trim(str_replace($path, '', $directory), DIRECTORY_SEPARATOR)) {
            $nested = str_replace(DIRECTORY_SEPARATOR, '.', $nested).'.';
        }

        return $nested;
    }

    /**
     * Throw an HttpException with the given data.
     *
     * @param  int  $code
     * @param  string  $message
     */
    public function abort($code, $message = '', array $headers = [])
    {
        if ($code == 404) {
            throw new NotFoundHttpException($message);
        }

        throw new HttpException($code, $message, null, $headers);
    }

    /**
     * Register a terminating callback with the application.
     *
     *
     * @return $this
     */
    public function terminating($callback)
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Terminate the application.
     */
    public function terminate()
    {
        foreach ($this->terminatingCallbacks as $terminating) {
            $this->call($terminating);
        }
    }

    /**
     * Handle incoming request and return a response.
     * Abstract the implementation from the user for easy
     * theme integration.
     *
     * @param  string  $kernel  Application kernel class name.
     * @param  SymfonyRequest  $request
     * @return $this
     */
    public function manage(string $kernel, $request)
    {
        $kernel = $this->make($kernel);

        $response = $kernel->handle($request);
        $response->sendHeaders();
        $response->sendContent();

        if (function_exists('shutdown_action_hook')) {
            shutdown_action_hook();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (! in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            Response::closeOutputBuffers(0, true);
        }

        $kernel->terminate($request, $response);

        return $this;
    }

    /**
     * Handle WordPress administration incoming request.
     * Only send response headers.
     *
     * @param  SymfonyRequest  $request
     * @return $this;
     */
    public function manageAdmin(string $kernel, $request)
    {
        if (! $this->isWordPressAdmin() && ! $this->has('action')) {
            return $this;
        }

        $this['action']->add('admin_init', $this->dispatchToAdmin($kernel, $request));

        return $this;
    }

    /**
     * Manage WordPress Admin Init.
     * Handle incoming request and return a response.
     *
     *
     * @return Closure
     */
    protected function dispatchToAdmin(string $kernel, $request)
    {
        return function () use ($kernel, $request) {
            $kernel = $this->make($kernel);

            /** @var Response $response */
            $response = $kernel->handle($request);

            if ($response->getStatusCode() >= 500) {
                // In case of an internal server error, we stop the process
                // and send full response back to the user.
                $response->send();
            } else {
                // HTTP OK - Send only the response headers.s
                $response->sendHeaders();
            }

            // WordPress renders the administration screen itself. Terminate
            // the kernel once it is done so terminable middleware and
            // deferred callbacks (defer() helper) are executed.
            $this['action']->add('shutdown', function () use ($kernel, $request, $response) {
                $kernel->terminate($request, $response);
            });
        };
    }

    /**
     * Register a callback to run after loading the environment.
     */
    public function afterLoadingEnvironment(Closure $callback)
    {
        $this->afterBootstrapping(EnvironmentLoader::class, $callback);
    }

    /**
     * Register a callback to run before a bootstrapper.
     *
     * @param  string  $bootstrapper
     */
    public function beforeBootstrapping($bootstrapper, Closure $callback)
    {
        $this['events']->listen('bootstrapping: '.$bootstrapper, $callback);
    }

    /**
     * Register a callback to run after a bootstrapper.
     *
     * @param  string  $bootstrapper
     */
    public function afterBootstrapping($bootstrapper, Closure $callback)
    {
        $this['events']->listen('bootstrapped: '.$bootstrapper, $callback);
    }

    /**
     * Set the application locale.
     *
     * @param  string  $locale
     */
    public function setLocale($locale)
    {
        $previous = $this['config']->get('app.locale');

        $this['config']->set('app.locale', $locale);
        $this['translator']->setLocale($locale);
        $this['events']->dispatch(new LocaleUpdated($locale, $previous));
    }

    /**
     * Get the application locale.
     *
     * @return string
     */
    public function getLocale()
    {
        return $this['config']->get('app.locale');
    }

    /**
     * Check if passed locale is current locale.
     *
     * @param  string  $locale
     * @return bool
     */
    public function isLocale($locale)
    {
        return $this->getLocale() == $locale;
    }

    /**
     * Get the current application locale.
     *
     * @return string
     */
    public function currentLocale()
    {
        return $this->getLocale();
    }

    /**
     * Get the current application fallback locale.
     *
     * @return string
     */
    public function getFallbackLocale()
    {
        return $this['config']->get('app.fallback_locale');
    }

    /**
     * Set the current application fallback locale.
     *
     * @param  string  $fallbackLocale
     * @return void
     */
    public function setFallbackLocale($fallbackLocale)
    {
        $this['config']->set('app.fallback_locale', $fallbackLocale);

        $this['translator']->setFallback($fallbackLocale);
    }

    /**
     * Return the application namespace.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function getNamespace()
    {
        if (! is_null($this->namespace)) {
            return $this->namespace;
        }

        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        foreach ((array) data_get($composer, 'autoload.psr-4') as $namespace => $path) {
            foreach ((array) $path as $pathChoice) {
                if (realpath(app_path()) == realpath(base_path($pathChoice))) {
                    return $this->namespace = $namespace;
                }
            }
        }

        throw new \RuntimeException('Unable to detect application namespace.');
    }

    /**
     * Determine if the application routes are cached.
     *
     * @return bool
     */
    public function routesAreCached()
    {
        if ($this->bound('routes.cached')) {
            return (bool) $this->make('routes.cached');
        }

        return $this->instance('routes.cached', $this['files']->exists($this->getCachedRoutesPath()));
    }

    /**
     * Get the path to the routes cache file.
     *
     * @return string
     */
    public function getCachedRoutesPath()
    {
        return $this->normalizeCachePath('APP_ROUTES_CACHE', 'cache/routes.php');
    }

    /**
     * Check if the application events are cached.
     *
     * @return bool
     */
    public function eventsAreCached()
    {
        if ($this->bound('events.cached')) {
            return (bool) $this->make('events.cached');
        }

        return $this->instance('events.cached', $this['files']->exists($this->getCachedEventsPath()));
    }

    /**
     * Get the path to the events cache file.
     *
     * @return string
     */
    public function getCachedEventsPath()
    {
        return $this->normalizeCachePath('APP_EVENTS_CACHE', 'cache/events.php');
    }

    /**
     * Normalize a relative or absolute path to a cache file.
     *
     * @param  string  $key
     * @param  string  $default
     * @return string
     */
    protected function normalizeCachePath($key, $default)
    {
        if (is_null($env = Env::get($key))) {
            return $this->bootstrapPath($default);
        }

        return Str::startsWith($env, '/')
            ? $env
            : $this->basePath($env);
    }

    /**
     * Determine if we currently inside the WordPress administration.
     *
     * @return bool
     */
    public function isWordPressAdmin()
    {
        if (isset($GLOBALS['current_screen']) && is_a($GLOBALS['current_screen'], 'WP_Screen')) {
            return $GLOBALS['current_screen']->in_admin();
        } elseif (defined('WP_ADMIN')) {
            return WP_ADMIN;
        }

        return false;
    }

    /**
     * Return a Javascript Global variable.
     *
     *
     * @return string
     */
    public function outputJavascriptGlobal(string $name, array $data)
    {
        $output = "<script type=\"text/javascript\">\n\r";
        $output .= "/* <![CDATA[ */\n\r";
        $output .= "var {$name} = {\n\r";

        if (! empty($data) && is_array($data)) {
            foreach ($data as $key => $value) {
                $output .= $key.': '.json_encode($value).",\n\r";
            }
        }

        $output .= "};\n\r";
        $output .= "/* ]]> */\n\r";
        $output .= '</script>';

        return $output;
    }
}
