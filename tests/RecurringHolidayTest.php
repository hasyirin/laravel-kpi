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
