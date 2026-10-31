<?php

use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Models\RecurringHoliday;
use Hasyirin\KPI\Tests\TestCase;
use Hasyirin\KPI\Tests\TestSupport\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// --- Upgrades from older versions ---

it('creates the recurring holidays table from a config published before v3.1', function () {
    Schema::drop('recurring_holidays');
    config()->set('kpi.tables', ['movements' => 'movements', 'holidays' => 'holidays']);

    $this->migration('create_recurring_holidays_table')->up();

    expect(Schema::hasTable('recurring_holidays'))->toBeTrue();
});

it('adds expects_children to a v1.0 movements table that already has parent_id', function () {
    Schema::drop('movements');
    $this->migration('create_movements_table')->up();
    Schema::table('movements', function (Blueprint $table) {
        $table->foreignId('parent_id')->after('id')->nullable()->index()->constrained('movements')->cascadeOnDelete();
    });

    $this->migration('add_parent_child_to_movements_table')->up();

    expect(Schema::hasColumns('movements', ['parent_id', 'expects_children']))->toBeTrue();
});

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

    expect(fn () => $movement->save())->toThrow(QueryException::class);
});

it('stops the movements migration while a movement has no received_at', function () {
    $this->migration('alter_previous_id_hours_and_received_at_on_movements_table')->down();
    DB::table('movements')->insert([
        'movable_id' => 1,
        'movable_type' => Task::class,
        'status' => 'legacy',
        'properties' => '[]',
        'received_at' => null,
    ]);

    expect(fn () => $this->migration('alter_previous_id_hours_and_received_at_on_movements_table')->up())
        ->toThrow(RuntimeException::class, 'Set received_at on every row of [movements]');
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

    foreach (array_filter(TestCase::MIGRATIONS, fn (string $name) => str_ends_with($name, 'movements_table')) as $migration) {
        $this->migration($migration)->up();
    }

    expect(collect(Schema::getForeignKeys('kpi_movements'))->pluck('foreign_table')->unique()->all())
        ->toBe(['kpi_movements']);
});

it('tracks movements in the configured movements table', function () {
    config()->set('kpi.tables.movements', 'kpi_movements');

    foreach (array_filter(TestCase::MIGRATIONS, fn (string $name) => str_ends_with($name, 'movements_table')) as $migration) {
        $this->migration($migration)->up();
    }

    $task = Task::create(['title' => 'Test']);
    $task->pass('open');
    $task->pass('closed');

    expect(DB::table('kpi_movements')->count())->toBe(2)
        ->and(DB::table('movements')->count())->toBe(0);
});
