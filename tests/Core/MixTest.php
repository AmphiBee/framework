<?php

namespace Themosis\Tests\Core;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Mix;

class MixTest extends TestCase
{
    protected $app;

    protected function setUp(): void
    {
        $this->getApplication();
    }

    protected function getApplication()
    {
        if (! is_null($this->app)) {
            return $this->app;
        }

        $app = new Application(dirname(__DIR__));

        $app->singleton('config', function () {
            return new Repository;
        });

        return $this->app = $app;
    }

    public function test_default_mix_usage()
    {
        $mix = app(Mix::class);

        $this->assertEquals(
            '/content/themes/underscore/dist/css/theme.css?id=ba9aead02aea5cc7befb',
            $mix('css/theme.css')->toHtml(),
        );
    }

    public function test_specifying_directory()
    {
        $mix = app(Mix::class);

        $this->assertEquals(
            '/content/themes/underscore/dist/css/theme.css?id=ba9aead02aea5cc7befb',
            $mix('css/theme.css', 'content/themes/underscore/dist')->toHtml(),
        );
    }

    public function test_calling_from_plugin()
    {
        $mix = app(Mix::class);

        $this->assertEquals(
            '/content/plugins/timeline/dist/css/theme.css?id=ba9aead02aea5cc7befb',
            $mix('css/theme.css', 'content/plugins/timeline/dist')->toHtml(),
        );
    }

    public function test_calling_from_web_root()
    {
        $mix = app(Mix::class);

        $this->assertEquals(
            '/css/theme.css?id=ba9aead02aea5cc7befb',
            $mix('css/theme.css', '/')->toHtml(),
        );
    }
}
