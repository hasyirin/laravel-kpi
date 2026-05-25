<?php

use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\RecurringHoliday;

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

it('occurrencesIn expands one date per year in range', function () {
    $h = RecurringHoliday::factory()->create(['month' => 5, 'day' => 1]);

    $dates = $h->occurrencesIn('2023-01-01', '2025-12-31');

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->format('Y-m-d'))->toBe('2023-05-01')
        ->and($dates[1]->format('Y-m-d'))->toBe('2024-05-01')
        ->and($dates[2]->format('Y-m-d'))->toBe('2025-05-01');
});

it('occurrencesIn skips Feb 29 in non-leap years', function () {
    $h = RecurringHoliday::factory()->create(['month' => 2, 'day' => 29]);

    $dates = $h->occurrencesIn('2023-01-01', '2026-12-31');

    // 2024 is leap; 2023, 2025, 2026 are not.
    expect($dates)->toHaveCount(1)
        ->and($dates[0]->format('Y-m-d'))->toBe('2024-02-29');
});

it('occurrencesIn honors effective_from', function () {
    $h = RecurringHoliday::factory()->create([
        'month' => 5,
        'day' => 1,
        'effective_from' => '2024-06-15',  // after May 1 2024, so 2024 occurrence excluded
    ]);

    $dates = $h->occurrencesIn('2023-01-01', '2025-12-31');

    expect($dates)->toHaveCount(1)
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-05-01');
});

it('occurrencesIn honors effective_until', function () {
    $h = RecurringHoliday::factory()->create([
        'month' => 5,
        'day' => 1,
        'effective_until' => '2024-12-31',
    ]);

    $dates = $h->occurrencesIn('2023-01-01', '2025-12-31');

    expect($dates)->toHaveCount(2)
        ->and($dates[0]->format('Y-m-d'))->toBe('2023-05-01')
        ->and($dates[1]->format('Y-m-d'))->toBe('2024-05-01');
});

it('occurrencesIn returns empty for an out-of-range query', function () {
    $h = RecurringHoliday::factory()->create(['month' => 5, 'day' => 1]);

    // May 1 not in Jan range.
    $dates = $h->occurrencesIn('2025-01-01', '2025-01-31');

    expect($dates)->toBeEmpty();
});
