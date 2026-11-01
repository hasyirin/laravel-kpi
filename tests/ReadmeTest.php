<?php

use Hasyirin\KPI\Facades\KPI;
use Illuminate\Support\Carbon;

it('matches the calculate example in the README', function () {
    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-01 08:00'),
        end: Carbon::parse('2025-01-03 15:30'),
    );

    expect($kpi->seconds)->toBe(91800)
        ->and($kpi->minutes())->toBe(1530.0)
        ->and($kpi->hours())->toBe(25.5)
        ->and($kpi->period)->toBe(3.0)
        ->and($kpi->metadata->scheduledDays)->toBe(3);
});

it('matches the excluded date example in the README', function () {
    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-01 08:00'),
        end: Carbon::parse('2025-01-03 15:30'),
        excludeDates: [Carbon::parse('2025-01-02')],
    );

    expect($kpi->seconds)->toBe(59400)
        ->and($kpi->minutes())->toBe(990.0)
        ->and($kpi->hours())->toBe(16.5)
        ->and($kpi->period)->toBe(2.0)
        ->and($kpi->metadata->scheduledDays)->toBe(2)
        ->and($kpi->metadata->excludedDays)->toBe(1);
});
