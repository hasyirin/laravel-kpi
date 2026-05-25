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
