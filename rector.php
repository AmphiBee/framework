<?php

declare(strict_types=1);

use Rector\Arguments\Rector\ClassMethod\ArgumentAdderRector;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;
use RectorLaravel\Set\LaravelLevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Generator stubs and WordPress fixtures are not regular PHP sources.
        __DIR__.'/src/*/stubs/*',
        __DIR__.'/tests/htdocs',
        // The upgrade sets rename concrete classes to their contracts. Here this would alias the
        // cache manager to the cache repository contract and type the validator with a contract
        // that lacks the methods Themosis calls (valid(), passes(), getData()...).
        RenameClassRector::class,
        ArgumentAdderRector::class => [
            __DIR__.'/src/Core/Application.php',
        ],
    ])
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
    ]);
