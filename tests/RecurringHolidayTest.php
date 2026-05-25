<?php

use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\RecurringHoliday;
use Illuminate\Support\Carbon;

it('persists observes_substitute on Holiday', function () {
    $holiday = Holiday::create([
        'name' => 'Test',
        'date' => '2026-01-01',
        'observes_substitute' => true,
    ]);

    expect($holiday->fresh()->observes_substitute)->toBeTrue();
});

it('honors config(kpi.tables.holidays) override on Holiday', function () {
    config(['kpi.tables.holidays' => 'my_custom_holidays']);

    expect((new Holiday)->getTable())->toBe('my_custom_holidays');
});

it('honors config(kpi.tables.recurring_holidays) override on RecurringHoliday', function () {
    config(['kpi.tables.recurring_holidays' => 'my_custom_recurring']);

    expect((new RecurringHoliday)->getTable())->toBe('my_custom_recurring');
});

it('effectiveIn returns rows with null bounds', function () {
    RecurringHoliday::factory()->create(['name' => 'A', 'month' => 5, 'day' => 1]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2020-01-01', '2030-12-31')
        ->get();

    expect($results)->toHaveCount(1);
});

it('effectiveIn excludes rows whose effective_until is before the range', function () {
    RecurringHoliday::factory()->create([
        'name' => 'Retired',
        'month' => 5,
        'day' => 1,
        'effective_until' => '2024-12-31',
    ]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2025-01-01', '2025-12-31')
        ->get();

    expect($results)->toBeEmpty();
});

it('effectiveIn excludes rows whose effective_from is after the range', function () {
    RecurringHoliday::factory()->create([
        'name' => 'Future',
        'month' => 5,
        'day' => 1,
        'effective_from' => '2030-01-01',
    ]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2025-01-01', '2025-12-31')
        ->get();

    expect($results)->toBeEmpty();
});

it('effectiveIn includes rows whose window partially overlaps the range', function () {
    RecurringHoliday::factory()->create([
        'name' => 'Bounded',
        'month' => 5,
        'day' => 1,
        'effective_from' => '2020-01-01',
        'effective_until' => '2025-06-30',
    ]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2025-01-01', '2025-12-31')
        ->get();

    expect($results)->toHaveCount(1);
});
