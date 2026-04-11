<?php

use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Facades\KPI;
use Hasyirin\KPI\Models\Holiday;
use Illuminate\Support\Carbon;

// --- Holiday exclusion ---

it('excludes a holiday from calculation', function () {
    Holiday::create(['name' => 'New Year Holiday', 'date' => '2025-01-02']);

    // Wed Jan 1 full day + Thu Jan 2 (holiday) + Fri Jan 3 full day
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 08:00'), Carbon::parse('2025-01-03 15:30'));

    expect($kpi)->toMatchArray(KPIData::make(
        minutes: 990,
        hours: 16.5,
        period: 2,
        metadata: KPIMetadata::make(minutes: 990, scheduled: 2, excluded: 1),
    )->toArray());
});

it('excludes multiple holidays from calculation', function () {
    Holiday::create(['name' => 'Holiday 1', 'date' => '2025-01-01']);
    Holiday::create(['name' => 'Holiday 2', 'date' => '2025-01-02']);

    // Wed Jan 1 (holiday) + Thu Jan 2 (holiday) + Fri Jan 3
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 08:00'), Carbon::parse('2025-01-03 15:30'));

    expect($kpi)->toMatchArray(KPIData::make(
        minutes: 450,
        hours: 7.5,
        period: 1,
        metadata: KPIMetadata::make(minutes: 450, scheduled: 1, excluded: 2),
    )->toArray());
});

it('does not double-count a holiday that falls on a weekend', function () {
    // Sat Jan 4 is already unscheduled
    Holiday::create(['name' => 'Weekend Holiday', 'date' => '2025-01-04']);

    // Fri Jan 3 + Sat Jan 4 (weekend, also holiday) + Sun Jan 5 (weekend) + Mon Jan 6
    $kpi = KPI::calculate(Carbon::parse('2025-01-03 08:00'), Carbon::parse('2025-01-06 17:00'));

    // Sat is unscheduled (skipped before holiday check), Sun is unscheduled, holiday doesn't apply
    expect($kpi->metadata->unscheduled)->toBe(2)
        ->and($kpi->metadata->excluded)->toBe(0)
        ->and($kpi->metadata->scheduled)->toBe(2);
});

// --- Custom exclude dates ---

it('excludes custom dates from calculation', function () {
    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-01 08:00'),
        end: Carbon::parse('2025-01-03 15:30'),
        excludeDates: [Carbon::parse('2025-01-02')],
    );

    expect($kpi->metadata->excluded)->toBe(1)
        ->and($kpi->metadata->scheduled)->toBe(2)
        ->and($kpi->minutes)->toBe(990.0);
});

it('combines custom exclude dates with holidays', function () {
    Holiday::create(['name' => 'Holiday', 'date' => '2025-01-01']);

    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-01 08:00'),
        end: Carbon::parse('2025-01-03 15:30'),
        excludeDates: [Carbon::parse('2025-01-02')],
    );

    // Both Jan 1 (holiday) and Jan 2 (custom) excluded, only Fri Jan 3 remains
    expect($kpi->metadata->excluded)->toBe(2)
        ->and($kpi->metadata->scheduled)->toBe(1)
        ->and($kpi->minutes)->toBe(450.0);
});

// --- Custom work schedules ---

it('uses custom work schedule', function () {
    $schedules = collect([
        Day::MONDAY->value => WorkSchedule::parse(['9:00', '15:00']),
    ]);

    // Mon Jan 6, custom 9:00-15:00 schedule (6 hours)
    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-06 09:00'),
        end: Carbon::parse('2025-01-06 15:00'),
        schedules: $schedules,
    );

    expect($kpi->minutes)->toBe(360.0)
        ->and($kpi->period)->toBe(1.0);
});

it('custom schedule with no entry for a day treats it as unscheduled', function () {
    // Only Monday is scheduled
    $schedules = collect([
        Day::MONDAY->value => WorkSchedule::parse(['8:00', '17:00']),
    ]);

    // Mon Jan 6 (scheduled) + Tue Jan 7 (unscheduled in custom)
    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-06 08:00'),
        end: Carbon::parse('2025-01-07 17:00'),
        schedules: $schedules,
    );

    expect($kpi->minutes)->toBe(540.0)
        ->and($kpi->metadata->scheduled)->toBe(1)
        ->and($kpi->metadata->unscheduled)->toBe(1);
});

// --- Edge cases ---

it('returns zero when start equals end', function () {
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 10:00'), Carbon::parse('2025-01-01 10:00'));

    expect($kpi->minutes)->toBe(0.0)
        ->and($kpi->period)->toBe(0.0);
});

it('returns zero when entire range falls outside work hours', function () {
    // 5:00 AM to 7:00 AM, before work starts at 8:00
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 05:00'), Carbon::parse('2025-01-01 07:00'));

    expect($kpi->minutes)->toBe(0.0)
        ->and($kpi->metadata->scheduled)->toBe(1);
});

it('handles range spanning only weekends', function () {
    // Sat Jan 4 to Sun Jan 5
    $kpi = KPI::calculate(Carbon::parse('2025-01-04 08:00'), Carbon::parse('2025-01-05 17:00'));

    expect($kpi->minutes)->toBe(0.0)
        ->and($kpi->metadata->unscheduled)->toBe(2)
        ->and($kpi->metadata->scheduled)->toBe(0);
});

it('clamps start time to work start when arriving early', function () {
    // Arrive at 6:00, work starts at 8:00, leave at 10:00 → 2 hours of work
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 06:00'), Carbon::parse('2025-01-01 10:00'));

    expect($kpi->minutes)->toBe(120.0);
});

it('clamps end time to work end when leaving late', function () {
    // Arrive at 15:00, work ends at 17:00 on wednesday, leave at 19:00 → 2 hours
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 15:00'), Carbon::parse('2025-01-01 19:00'));

    expect($kpi->minutes)->toBe(120.0);
});
