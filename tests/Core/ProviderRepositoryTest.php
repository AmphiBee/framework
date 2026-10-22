<?php

namespace Themosis\Tests\Core;

use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\ProviderRepository;

class ProviderRepositoryTest extends TestCase
{
    public function test_services_are_registered_when_manifest_is_not_recompiled()
    {
        $app = $this->getMockBuilder('Themosis\Core\Application')
            ->onlyMethods(['register', 'addDeferredServices', 'runningInConsole'])
            ->getMock();
        $repository = $this->getMockBuilder('Themosis\Core\ProviderRepository')
            ->setConstructorArgs([
                $app,
                $this->getMockBuilder('Illuminate\Filesystem\Filesystem')->getMock(),
                __DIR__.'/services.php',
            ])
            ->onlyMethods([
                'loadManifest',
                'shouldRecompile',
                'compileManifest',
                'createProvider',
            ])
            ->getMock();
        $repository->expects($this->once())
            ->method('loadManifest')
            ->willReturn([
                'eager' => ['foo'],
                'deferred' => ['deferred'],
                'providers' => ['providers'],
                'when' => [],
            ]);
        $repository->expects($this->once())
            ->method('shouldRecompile')
            ->willReturn(false);
        $app->expects($this->once())->method('register')->with('foo');
        $app->expects($this->any())->method('runningInConsole')->willReturn(false);
        $app->expects($this->once())->method('addDeferredServices')->with(['deferred']);

        $repository->load([]);
    }

    public function test_manifest_is_properly_recompiled()
    {
        $app = $this->getMockBuilder('Themosis\Core\Application')
            ->onlyMethods(['register', 'addDeferredServices', 'runningInConsole'])
            ->getMock();
        $repository = $this->getMockBuilder('Themosis\Core\ProviderRepository')
            ->setConstructorArgs([
                $app,
                $this->getMockBuilder('Illuminate\Filesystem\Filesystem')->getMock(),
                __DIR__.'/services.php',
            ])
            ->onlyMethods([
                'loadManifest',
                'shouldRecompile',
                'createProvider',
            ])
            ->getMock();

        $repository->expects($this->once())
            ->method('loadManifest')
            ->willReturn([
                'eager' => [],
                'deferred' => ['deferred'],
            ]);
        $repository->expects($this->once())->method('shouldRecompile')->willReturn(true);

        // foo mock is just a deferred provider
        $repository->expects($this->once())
            ->method('createProvider')
            ->with('foo')
            ->willReturn($fooMock = $this->getMockBuilder('Illuminate\Support\ServiceProvider')
                ->disableOriginalConstructor()
                ->onlyMethods(['isDeferred', 'provides', 'when'])
                ->getMock(), );
        $fooMock->expects($this->once())->method('isDeferred')->willReturn(true);
        $fooMock->expects($this->once())->method('provides')->willReturn(['foo.provides1', 'foo.provides2']);
        $fooMock->expects($this->once())->method('when')->willReturn([]);

        // bar mock is added to eagers since it's not reserved
        /*$repository
            ->method('createProvider')
            ->with('bar')
            ->willReturn($barMock = $this->getMockBuilder('Illuminate\Support\ServiceProvider')
                ->setConstructorArgs([$app])
                ->onlyMethods(['isDeferred'])
                ->getMock());*/

        // $app->expects($this->once())->method('register')->with('bar');

        $app->expects($this->any())->method('runningInConsole')->willReturn(false);
        $app->expects($this->once())->method('addDeferredServices')->with([
            'foo.provides1' => 'foo',
            'foo.provides2' => 'foo',
        ]);

        $repository->load(['foo']);
    }

    public function test_should_recompile_returns_correct_value()
    {
        $repo = new ProviderRepository(
            new Application,
            $this->getMockBuilder('Illuminate\Filesystem\Filesystem')->getMock(),
            __DIR__.'/services.php',
        );

        $this->assertTrue($repo->shouldRecompile(null, []));
        $this->assertTrue($repo->shouldRecompile(['providers' => ['foo']], ['foo', 'bar']));
        $this->assertFalse($repo->shouldRecompile(['providers' => ['foo']], ['foo']));
    }

    public function test_load_manifest_returns_parsed_json()
    {
        $repo = new ProviderRepository(
            new Application,
            $files = $this->getMockBuilder('Illuminate\Filesystem\Filesystem')
                ->onlyMethods(['exists', 'getRequire'])
                ->getMock(),
            __DIR__.'/services.php',
        );

        $files->expects($this->once())
            ->method('exists')
            ->with(__DIR__.'/services.php')
            ->willReturn(true);
        $files->expects($this->once())
            ->method('getRequire')
            ->with(__DIR__.'/services.php')
            ->willReturn($array = ['users' => ['joe' => true], 'when' => []]);

        $this->assertEquals($array, $repo->loadManifest());
    }

    public function test_write_manifest_stores_to_proper_location()
    {
        $repo = new ProviderRepository(
            new Application,
            $files = $this->getMockBuilder('Illuminate\Filesystem\Filesystem')
                ->onlyMethods(['replace'])
                ->getMock(),
            __DIR__.'/services.php',
        );

        $files->expects($this->once())
            ->method('replace')
            ->with(__DIR__.'/services.php', '<?php return '.var_export(['foo'], true).';');
        $result = $repo->writeManifest(['foo']);
        $this->assertEquals(['foo', 'when' => []], $result);
    }
}
