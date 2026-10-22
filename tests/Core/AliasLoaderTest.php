<?php

namespace Themosis\Tests\Core;

use PHPUnit\Framework\TestCase;
use Themosis\Core\AliasLoader;

class AliasLoaderTest extends TestCase
{
    public function test_loader_can_be_created_and_registered_once()
    {
        $loader = AliasLoader::getInstance(['foo' => 'bar']);

        $this->assertEquals(['foo' => 'bar'], $loader->getAliases());
        $this->assertFalse($loader->isRegistered());

        $loader->register();
        $this->assertTrue($loader->isRegistered());
    }

    public function test_get_instance_create_one_instance()
    {
        $loader = AliasLoader::getInstance(['some' => 'thing']);
        $this->assertSame($loader, AliasLoader::getInstance());
    }

    public function test_alias_are_registered_after_instantiation()
    {
        $loader = AliasLoader::getInstance(['bar' => 'baz']);
        $loader->alias('foo', 'doe');

        // Still same instance on all tests.
        $this->assertEquals([
            'bar' => 'baz',
            'foo' => 'doe',
            'some' => 'thing',
        ], $loader->getAliases());
    }
}
