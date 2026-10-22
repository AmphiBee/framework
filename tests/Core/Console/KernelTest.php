<?php

namespace Themosis\Tests\Core\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Themosis\Core\Application;
use Themosis\Core\Console\Kernel;

class KernelTest extends TestCase
{
    protected function tearDown(): void
    {
        Artisan::forgetBootstrappers();
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_attribute_commands_are_lazily_loaded()
    {
        [$kernel] = $this->getKernel();

        $artisan = (fn () => $this->getArtisan())->call($kernel);

        $this->assertTrue($artisan->has('themosis:lazy'));
    }

    public function test_command_events_are_dispatched()
    {
        [$kernel, $events] = $this->getKernel();

        $dispatched = [];
        $events->listen(CommandStarting::class, function ($event) use (&$dispatched) {
            $dispatched[] = 'starting:'.$event->command;
        });
        $events->listen(CommandFinished::class, function ($event) use (&$dispatched) {
            $dispatched[] = 'finished:'.$event->command.':'.$event->exitCode;
        });

        $kernel->rerouteSymfonyCommandEvents();

        $artisan = (fn () => $this->getArtisan())->call($kernel);
        $artisan->setAutoExit(false);
        $artisan->run(new ArrayInput(['command' => 'themosis:lazy']), $output = new BufferedOutput);

        $this->assertSame("lazy\n", $output->fetch());
        $this->assertSame(['starting:themosis:lazy', 'finished:themosis:lazy:0'], $dispatched);
    }

    public function test_console_schedule_can_be_resolved()
    {
        [$kernel] = $this->getKernel();

        $schedule = $kernel->resolveConsoleSchedule();

        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertCount(1, $schedule->events());
    }

    protected function getKernel(): array
    {
        $app = new Application;
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $app->instance('env', 'testing');
        $app->instance(EventMutex::class, $this->createMock(EventMutex::class));
        $app->instance(SchedulingMutex::class, $this->createMock(SchedulingMutex::class));
        $events = new Dispatcher($app);
        $app->instance('events', $events);

        return [new KernelTestKernelStub($app, $events), $events];
    }
}

class KernelTestKernelStub extends Kernel
{
    protected $commands = [
        KernelTestLazyCommand::class,
    ];

    protected function scheduleCache()
    {
        return null;
    }

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('themosis:lazy')->daily();
    }
}

#[AsCommand(name: 'themosis:lazy')]
class KernelTestLazyCommand extends Command
{
    protected $signature = 'themosis:lazy';

    public function handle()
    {
        $this->line('lazy');
    }
}
