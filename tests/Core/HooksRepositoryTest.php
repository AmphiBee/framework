<?php

namespace Themosis\Tests\Core;

use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\HooksRepository;
use Themosis\Hook\Hookable;

class HooksRepositoryTest extends TestCase
{
    public function test_hookables_classes_are_registered()
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();

        $app->expects($this->exactly(2))
            ->method('registerHook');

        (new HooksRepository($app))->load([
            'Some\Namespace\Hookable',
            MyActions::class,
        ]);
    }
}

class MyActions extends Hookable
{
    public function register() {}
}
