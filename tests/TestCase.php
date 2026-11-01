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
        'create_movements_table',
        'create_recurring_holidays_table',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A model booted in an earlier test keeps its listeners on that test's event
        // dispatcher. Clear so the model boots again with the current dispatcher.
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

        $driver = env('DB_CONNECTION');

        if ($driver) {
            config()->set('database.connections.testing', [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT'),
                'database' => env('DB_DATABASE', 'kpi'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
            $app['db']->connection()->getSchemaBuilder()->dropAllTables();
        } else {
            config()->set('database.connections.testing.foreign_key_constraints', true);
        }

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
