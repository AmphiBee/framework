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

    public function test_console_environment_detection_with_space_separated_value()
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'goo';
        }, ['--env', 'staging']);

        $this->assertEquals('staging', $result);
    }

    public function test_console_environment_detection_ignores_similar_options()
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'goo';
        }, ['--envelope=foo']);

        $this->assertEquals('goo', $result);
    }
}
