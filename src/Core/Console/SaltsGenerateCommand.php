<?php

namespace Themosis\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class SaltsGenerateCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'salts:generate
                    {--show : Display the keys instead of modifying files}
                    {--force : Force the operation to run when in production}';

    /**
     * @var string
     */
    protected $description = 'Set the WordPress salt keys';

    /**
     * @var array
     */
    protected $keys = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ];

    /**
     * @var string
     */
    protected $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#$%&()*+,-./: ;<=>?@[]^_`{|}~';

    public function handle()
    {
        $saltKeys = [];

        foreach ($this->keys as $key) {
            $salt = $this->generateRandomSalt(64, $this->chars);

            $saltKeys[$key] = $salt;
        }

        if ($this->option('show')) {
            $this->showSaltKeys($saltKeys);

            return;
        }

        if (! $this->setKeysInEnvironmentFile($saltKeys)) {
            return;
        }

        $this->info('Application salt keys set successfully.');
    }

    /**
     * Display the salt keys.
     */
    protected function showSaltKeys(array $salts)
    {
        foreach ($salts as $key => $salt) {
            $this->line("<comment>{$key}={$salt}</comment>");
        }
    }

    protected function setKeysInEnvironmentFile(array $salts)
    {
        if (! $this->confirmToProceed()) {
            return false;
        }

        foreach ($salts as $key => $salt) {
            $this->writeNewEnvironmentFileWith($key, $salt);
        }

        return true;
    }

    /**
     * Write new environment file with the given key.
     */
    protected function writeNewEnvironmentFileWith(string $key, string $salt)
    {
        $path = $this->laravel->environmentFilePath();
        $contents = file_get_contents($path);

        // Single quotes keep the salt literal for phpdotenv ("$" and "${" are not
        // interpolated) and the salt characters never include a single quote.
        $line = $key."='".$salt."'";

        // A callback avoids "$1"-like sequences of the salt being read as back-references.
        $replaced = preg_replace_callback(
            $this->keyReplacementPattern($key),
            fn () => $line,
            $contents,
            1,
            $count,
        );

        if ($count === 0) {
            $replaced = rtrim($contents, PHP_EOL).PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($path, $replaced);
    }

    /**
     * Get a regex pattern that will match the whole env line of the given salt key,
     * whatever its current value and quoting.
     *
     * @return string
     */
    protected function keyReplacementPattern(string $key)
    {
        return '/^'.preg_quote($key, '/').'=.*$/m';
    }

    /**
     * Generate the random string salt,
     *
     *
     * @return string
     *
     * @throws \SodiumException
     */
    protected function generateRandomSalt(int $length, string $chars)
    {
        $salt = '';

        for ($i = 0; $i < $length; $i++) {
            $salt .= $chars[\Sodium\randombytes_uniform(strlen($chars))];
        }

        return $salt;
    }
}
