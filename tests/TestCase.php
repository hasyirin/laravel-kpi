<?php

namespace Hasyirin\KPI\Tests;

use Hasyirin\KPI\KPIServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    public const MIGRATIONS = [
        'create_holidays_table',
        'add_observes_substitute_to_holidays_table',
        'create_movements_table',
        'add_parent_child_to_movements_table',
        'create_recurring_holidays_table',
        'alter_previous_id_hours_and_received_at_on_movements_table',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Migration stubs instantiate models (for foreignIdFor()), which boots them
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
        config()->set('database.connections.testing.foreign_key_constraints', true);

        foreach (self::MIGRATIONS as $migration) {
            $this->migration($migration)->up();
        }

        $app['db']->connection()->getSchemaBuilder()->create('tasks', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    protected function migration(string $name): Migration
    {
        return include __DIR__.'/../database/migrations/'.$name.'.php.stub';
    }
}
