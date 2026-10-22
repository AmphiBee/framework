<?php

namespace Themosis\Tests\Core;

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\CallQueuedClosure;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Bus\PendingChain;
use Themosis\Core\Bus\PendingClosureDispatch;
use Themosis\Core\Bus\PendingDispatch;
use Themosis\Core\Support\FoundationAliases;

class HelpersTest extends TestCase
{
    protected $app;

    protected $reported = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application;
        $this->reported = [];

        $handler = $this->createMock(ExceptionHandler::class);
        $handler->method('report')->willReturnCallback(function ($e) {
            $this->reported[] = $e;
        });

        $this->app->instance(ExceptionHandler::class, $handler);

        // Pending dispatches are sent to the bus when destructed.
        $this->app->instance(Dispatcher::class, $this->createMock(Dispatcher::class));
    }

    public function test_report_accepts_a_string()
    {
        report('Something happened');

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(\Exception::class, $this->reported[0]);
        $this->assertSame('Something happened', $this->reported[0]->getMessage());
    }

    public function test_report_if_and_report_unless()
    {
        report_if(false, 'not reported');
        report_unless(true, 'not reported');
        report_if(true, 'reported');
        report_unless(false, 'reported');

        $this->assertCount(2, $this->reported);
    }

    public function test_rescue_accepts_a_callable_to_decide_reporting()
    {
        $result = rescue(function () {
            throw new \RuntimeException('Oops');
        }, 'rescued', function (\Throwable $e) {
            return $e->getMessage() !== 'Oops';
        });

        $this->assertSame('rescued', $result);
        $this->assertCount(0, $this->reported);
    }

    public function test_dispatch_wraps_closures_in_a_pending_closure_dispatch()
    {
        $pending = dispatch(function () {
            //
        });

        $this->assertInstanceOf(PendingClosureDispatch::class, $pending);
        $this->assertInstanceOf(PendingDispatch::class, $pending);
        $this->assertInstanceOf(CallQueuedClosure::class, $pending->getJob());
    }

    public function test_foundation_classes_used_by_illuminate_packages_are_aliased()
    {
        foreach (FoundationAliases::getAliases() as $alias => $class) {
            $this->assertTrue(
                class_exists($alias) || trait_exists($alias),
                "{$alias} is not resolvable.",
            );
        }

        $this->assertInstanceOf(PendingChain::class, (new BusDispatcher($this->app))->chain([function () {
            //
        }]));
    }
}
