<?php

namespace Hasyirin\KPI\Tests;

use Hasyirin\KPI\KPIServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Migration stubs instantiate models (for getTable()), which boots them
        // on a stale event dispatcher. Clear so they re-boot with the test dispatcher.
        Model::clearBootedModels();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Hasyirin\\KPI\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            KPIServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        $migrations = [
            'create_holidays_table',
            'create_movements_table',
            'add_parent_child_to_movements_table',
        ];

        foreach ($migrations as $migration) {
            (include __DIR__.'/../database/migrations/'.$migration.'.php.stub')->up();
        }

        $app['db']->connection()->getSchemaBuilder()->create('tasks', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }
}
