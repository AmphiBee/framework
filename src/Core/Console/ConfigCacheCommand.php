<?php

namespace Themosis\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use LogicException;
use Throwable;

class ConfigCacheCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'config:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a cache file for faster configuration loading';

    /**
     * @var Filesystem
     */
    protected $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->call('config:clear');

        $config = $this->getFreshConfiguration();

        $configPath = $this->laravel->getCachedConfigPath();

        $this->files->put(
            $configPath,
            '<?php return '.var_export($config, true).';'.PHP_EOL,
        );

        try {
            require $configPath;
        } catch (Throwable $exception) {
            $this->files->delete($configPath);

            foreach (Arr::dot($config) as $key => $value) {
                try {
                    eval(var_export($value, true).';');
                } catch (Throwable $e) {
                    throw new LogicException("Your configuration files could not be serialized because the value at \"{$key}\" is non-serializable.", 0, $e);
                }
            }

            throw new LogicException('Your configuration files are not serializable.', 0, $exception);
        }

        $this->info('Configuration cached successfully!');
    }

    /**
     * Boot a fresh copy of the application configuration.
     *
     * @return array
     */
    protected function getFreshConfiguration()
    {
        $app = require $this->laravel->bootstrapPath('app.php');

        $app->useStoragePath($this->laravel->storagePath());

        $app->make(ConsoleKernelContract::class)->bootstrap();

        return $app['config']->all();
    }
}
