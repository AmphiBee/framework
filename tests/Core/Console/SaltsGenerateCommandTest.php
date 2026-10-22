<?php

namespace Themosis\Tests\Core\Console;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Themosis\Core\Application;
use Themosis\Core\Console\SaltsGenerateCommand;

class SaltsGenerateCommandTest extends TestCase
{
    protected $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/themosis-salts-'.uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/.env');
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_salts_are_replaced_whatever_their_quoting_and_missing_ones_are_added()
    {
        file_put_contents($this->directory.'/.env', implode(PHP_EOL, [
            'APP_ENV=local',
            "AUTH_KEY='old-single'",
            'SECURE_AUTH_KEY="old-double"',
            'LOGGED_IN_KEY=old-bare',
            'NONCE_KEY=\'with $1 and ${APP_ENV}\'',
            "AUTH_SALT=''",
            'SECURE_AUTH_SALT=',
            'LOGGED_IN_SALT=x',
            'DB_NAME=wordpress',
        ]).PHP_EOL);

        $this->runCommand();

        $contents = file_get_contents($this->directory.'/.env');
        $values = Dotenv::parse($contents);

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) {
            $this->assertSame(1, preg_match_all('/^'.$key.'=/m', $contents), "{$key} must appear exactly once.");
            $this->assertSame(64, strlen($values[$key]), "{$key} must be read back as the full 64 chars salt.");
            $this->assertStringNotContainsString('old-', $values[$key]);
        }

        $this->assertSame('local', $values['APP_ENV']);
        $this->assertSame('wordpress', $values['DB_NAME']);
    }

    public function test_written_salts_match_the_generated_ones()
    {
        file_put_contents($this->directory.'/.env', "AUTH_KEY='old'".PHP_EOL);

        // Force salts made of characters that preg_replace() would interpret.
        $command = new class extends SaltsGenerateCommand
        {
            protected function generateRandomSalt(int $length, string $chars)
            {
                return str_pad('$1\\0${A}', $length, '$');
            }
        };

        $this->runCommand($command);

        $values = Dotenv::parse(file_get_contents($this->directory.'/.env'));

        $this->assertSame(str_pad('$1\\0${A}', 64, '$'), $values['AUTH_KEY']);
    }

    protected function runCommand(?SaltsGenerateCommand $command = null): void
    {
        $app = new Application;
        $app->useEnvironmentPath($this->directory);
        $app['env'] = 'testing';

        $command ??= new SaltsGenerateCommand;
        $command->setLaravel($app);

        (new CommandTester($command))->execute([]);
    }
}
