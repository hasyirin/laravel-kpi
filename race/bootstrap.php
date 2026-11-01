<?php

declare(strict_types=1);

use Hasyirin\KPI\Concerns\InteractsWithMovement;
use Hasyirin\KPI\Contracts\HasMovement;
use Hasyirin\KPI\KPIServiceProvider;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../vendor/autoload.php';

const RACE_RESOURCE_CONNECTION = 'race_resources';
const RACE_MOVEMENT_CONNECTION = 'race_movements';

class RaceResource extends Model implements HasMovement
{
    use InteractsWithMovement;

    protected $connection = RACE_RESOURCE_CONNECTION;

    protected $table = 'race_resources';

    protected $guarded = [];
}

class RaceMovement extends Movement
{
    protected $connection = RACE_MOVEMENT_CONNECTION;
}

function raceBoot(string $driver): ApplicationContract
{
    $app = Application::create(options: [
        'load_environment_variables' => false,
        'extra' => ['providers' => [KPIServiceProvider::class], 'dont-discover' => ['*']],
    ]);

    $connection = [
        'driver' => $driver,
        'host' => getenv('DB_HOST'),
        'port' => getenv('DB_PORT'),
        'database' => getenv('DB_DATABASE'),
        'username' => getenv('DB_USERNAME'),
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
        'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ];

    $app['config']->set('database.default', 'race');
    $app['config']->set('database.connections.race', $connection);
    $app['config']->set('database.connections.'.RACE_RESOURCE_CONNECTION, $connection);
    $app['config']->set('database.connections.'.RACE_MOVEMENT_CONNECTION, $connection);

    return $app;
}

function raceDisableForeignKeys(): string
{
    return getenv('DB_CONNECTION') === 'pgsql'
        ? "SET session_replication_role = 'replica'"
        : 'SET foreign_key_checks = 0';
}

function raceLockTimeout(): string
{
    return getenv('DB_CONNECTION') === 'pgsql'
        ? "SET lock_timeout = '1s'"
        : 'SET innodb_lock_wait_timeout = 1';
}
