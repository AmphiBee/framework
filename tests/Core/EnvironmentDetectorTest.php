<?php

namespace Themosis\Tests\Core;

use PHPUnit\Framework\TestCase;
use Themosis\Core\EnvironmentDetector;

class EnvironmentDetectorTest extends TestCase
{
    public function test_closure_can_be_used_for_environment_detection()
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foo';
        });

        $this->assertEquals('foo', $result);
    }

    public function test_console_environment_detection()
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'goo';
        }, ['--env=local']);

        $this->assertEquals('local', $result);
    }
}
