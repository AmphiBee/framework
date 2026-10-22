<?php

namespace Themosis\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Console\ControllerMakeCommand;
use ReflectionClass;

class StubPublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stub:publish {--force : Overwrite any existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish all stubs that are available for customization';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! is_dir($stubsPath = $this->laravel->basePath('stubs'))) {
            (new Filesystem)->makeDirectory($stubsPath);
        }

        $databaseConsolePath = dirname((new ReflectionClass(FactoryMakeCommand::class))->getFileName(), 2);
        $migrationsPath = dirname((new ReflectionClass(MigrationCreator::class))->getFileName());
        $routingConsolePath = dirname((new ReflectionClass(ControllerMakeCommand::class))->getFileName());

        $files = [
            __DIR__.'/stubs/cast.stub' => $stubsPath.'/cast.stub',
            __DIR__.'/stubs/console.stub' => $stubsPath.'/console.stub',
            __DIR__.'/stubs/job.queued.stub' => $stubsPath.'/job.queued.stub',
            __DIR__.'/stubs/job.stub' => $stubsPath.'/job.stub',
            __DIR__.'/stubs/model.pivot.stub' => $stubsPath.'/model.pivot.stub',
            __DIR__.'/stubs/model.stub' => $stubsPath.'/model.stub',
            __DIR__.'/stubs/observer.stub' => $stubsPath.'/observer.stub',
            __DIR__.'/stubs/observer.plain.stub' => $stubsPath.'/observer.plain.stub',
            __DIR__.'/stubs/policy.plain.stub' => $stubsPath.'/policy.plain.stub',
            __DIR__.'/stubs/policy.stub' => $stubsPath.'/policy.stub',
            __DIR__.'/stubs/request.stub' => $stubsPath.'/request.stub',
            __DIR__.'/stubs/resource.stub' => $stubsPath.'/resource.stub',
            __DIR__.'/stubs/resource-collection.stub' => $stubsPath.'/resource-collection.stub',
            __DIR__.'/stubs/rule.stub' => $stubsPath.'/rule.stub',
            __DIR__.'/stubs/rule.implicit.stub' => $stubsPath.'/rule.implicit.stub',
            __DIR__.'/stubs/test.stub' => $stubsPath.'/test.stub',
            __DIR__.'/stubs/test.unit.stub' => $stubsPath.'/test.unit.stub',
            __DIR__.'/stubs/pest.stub' => $stubsPath.'/pest.stub',
            __DIR__.'/stubs/pest.unit.stub' => $stubsPath.'/pest.unit.stub',
            $databaseConsolePath.'/Factories/stubs/factory.stub' => $stubsPath.'/factory.stub',
            $databaseConsolePath.'/Seeds/stubs/seeder.stub' => $stubsPath.'/seeder.stub',
            $migrationsPath.'/stubs/migration.create.stub' => $stubsPath.'/migration.create.stub',
            $migrationsPath.'/stubs/migration.stub' => $stubsPath.'/migration.stub',
            $migrationsPath.'/stubs/migration.update.stub' => $stubsPath.'/migration.update.stub',
            $routingConsolePath.'/stubs/controller.api.stub' => $stubsPath.'/controller.api.stub',
            $routingConsolePath.'/stubs/controller.invokable.stub' => $stubsPath.'/controller.invokable.stub',
            $routingConsolePath.'/stubs/controller.model.api.stub' => $stubsPath.'/controller.model.api.stub',
            $routingConsolePath.'/stubs/controller.model.stub' => $stubsPath.'/controller.model.stub',
            $routingConsolePath.'/stubs/controller.nested.api.stub' => $stubsPath.'/controller.nested.api.stub',
            $routingConsolePath.'/stubs/controller.nested.singleton.api.stub' => $stubsPath.'/controller.nested.singleton.api.stub',
            $routingConsolePath.'/stubs/controller.nested.singleton.stub' => $stubsPath.'/controller.nested.singleton.stub',
            $routingConsolePath.'/stubs/controller.nested.stub' => $stubsPath.'/controller.nested.stub',
            $routingConsolePath.'/stubs/controller.plain.stub' => $stubsPath.'/controller.plain.stub',
            $routingConsolePath.'/stubs/controller.singleton.api.stub' => $stubsPath.'/controller.singleton.api.stub',
            $routingConsolePath.'/stubs/controller.singleton.stub' => $stubsPath.'/controller.singleton.stub',
            $routingConsolePath.'/stubs/controller.stub' => $stubsPath.'/controller.stub',
            $routingConsolePath.'/stubs/middleware.stub' => $stubsPath.'/middleware.stub',
        ];

        foreach ($files as $from => $to) {
            if (! file_exists($to) || $this->option('force')) {
                file_put_contents($to, file_get_contents($from));
            }
        }

        $this->info('Stubs published successfully.');
    }
}
