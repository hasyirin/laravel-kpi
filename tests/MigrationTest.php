<?php

use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Models\RecurringHoliday;
use Hasyirin\KPI\Tests\TestCase;
use Hasyirin\KPI\Tests\TestSupport\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// --- Rollback ---

it('rolls back every package migration', function () {
    foreach (array_reverse(TestCase::MIGRATIONS) as $migration) {
        $this->migration($migration)->down();
    }

    expect(Schema::hasTable('movements'))->toBeFalse()
        ->and(Schema::hasTable('holidays'))->toBeFalse()
        ->and(Schema::hasTable('recurring_holidays'))->toBeFalse();
});

// --- Movements table ---

it('rejects a movement without received_at', function () {
    $movement = new Movement([
        'movable_id' => 1,
        'movable_type' => Task::class,
        'status' => 'open',
        'received_at' => null,
        'properties' => [],
    ]);
    $movement->position = 1;

    expect(fn () => $movement->save())->toThrow(QueryException::class);
});

// --- Custom table names ---

it('uses the configured movements table', function () {
    config()->set('kpi.tables.movements', 'kpi_movements');

    expect((new Movement)->getTable())->toBe('kpi_movements');
});

it('prefers a subclass table over the configured table', function () {
    $movement = new class extends Movement
    {
        protected $table = 'custom_movements';
    };
    $holiday = new class extends Holiday
    {
        protected $table = 'custom_holidays';
    };
    $recurringHoliday = new class extends RecurringHoliday
    {
        protected $table = 'custom_recurring_holidays';
    };

    expect($movement->getTable())->toBe('custom_movements')
        ->and($holiday->getTable())->toBe('custom_holidays')
        ->and($recurringHoliday->getTable())->toBe('custom_recurring_holidays');
});

it('points the self-referencing foreign keys at the configured movements table', function () {
    config()->set('kpi.tables.movements', 'kpi_movements');

    $this->migration('create_movements_table')->up();

    expect(collect(Schema::getForeignKeys('kpi_movements'))->pluck('foreign_table')->unique()->all())
        ->toBe(['kpi_movements']);
});

it('tracks movements in the configured movements table', function () {
    config()->set('kpi.tables.movements', 'kpi_movements');

    $this->migration('create_movements_table')->up();

    $task = Task::create(['title' => 'Test']);
    $task->pass('open');
    $task->pass('closed');

    expect(DB::table('kpi_movements')->count())->toBe(2)
        ->and(DB::table('movements')->count())->toBe(0);
});

// --- Schema ---

it('stores integer seconds, not hours', function () {
    expect(Schema::hasColumn('movements', 'seconds'))->toBeTrue()
        ->and(Schema::hasColumn('movements', 'hours'))->toBeFalse()
        ->and(Schema::hasColumn('movements', 'deleted_at'))->toBeFalse();
});

it('has a unique index on the resource, the level and the position', function () {
    $unique = collect(Schema::getIndexes('movements'))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->pluck('columns')
        ->all();

    expect($unique)->toBe([['movable_type', 'movable_id', 'level_key', 'position']]);
});

it('generates level_key in the database', function () {
    DB::table('tasks')->insert(['title' => 'T']);

    expect(fn () => DB::table('movements')->insert([
        'movable_type' => 'task', 'movable_id' => 1, 'position' => 1, 'level_key' => 5, 'status' => 'open',
        'properties' => '[]', 'received_at' => '2025-01-01 00:00:00',
    ]))->toThrow(QueryException::class);
});

it('uses dateTime columns for the movement times', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
        $this->markTestSkipped('PostgreSQL reports dateTime as timestamp, and only MySQL TIMESTAMP has the 2038 limit.');
    }

    $types = collect(Schema::getColumns('movements'))->pluck('type_name', 'name');

    expect($types['received_at'])->toBe('datetime')
        ->and($types['completed_at'])->toBe('datetime');
});

it('adds the CHECK constraints to the prefixed table', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('The stub does not add CHECK constraints on SQLite.');
    }

    DB::getSchemaBuilder()->dropIfExists('prefixed_movements');
    DB::connection()->setTablePrefix('kpi_');
    config()->set('kpi.tables.movements', 'prefixed_movements');

    try {
        $this->migration('create_movements_table')->up();

        DB::table('prefixed_movements')->insert([
            'movable_type' => 'task', 'movable_id' => 1, 'position' => 1, 'status' => 'open', 'properties' => '[]',
            'received_at' => '2025-01-02 00:00:00', 'completed_at' => null,
        ]);

        expect(fn () => DB::table('prefixed_movements')->update(['completed_at' => '2025-01-01 00:00:00']))
            ->toThrow(QueryException::class);
    } finally {
        Schema::dropIfExists('prefixed_movements');
        DB::connection()->setTablePrefix('');
    }
});
