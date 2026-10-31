<?php

use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Facades\KPI;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Models\RecurringHoliday;
use Illuminate\Support\Carbon;

// --- Holiday exclusion ---

it('excludes a holiday from calculation', function () {
    Holiday::create(['name' => 'New Year Holiday', 'date' => '2025-01-02']);

    // Wed Jan 1 full day + Thu Jan 2 (holiday) + Fri Jan 3 full day
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 08:00'), Carbon::parse('2025-01-03 15:30'));

    expect($kpi)->toMatchArray(KPIData::make(
        seconds: 59400,
        period: 2,
        metadata: KPIMetadata::make(scheduledSeconds: 59400, scheduledDays: 2, excludedDays: 1),
    )->toArray());
});

it('excludes multiple holidays from calculation', function () {
    Holiday::create(['name' => 'Holiday 1', 'date' => '2025-01-01']);
    Holiday::create(['name' => 'Holiday 2', 'date' => '2025-01-02']);

    // Wed Jan 1 (holiday) + Thu Jan 2 (holiday) + Fri Jan 3
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 08:00'), Carbon::parse('2025-01-03 15:30'));

    expect($kpi)->toMatchArray(KPIData::make(
        seconds: 27000,
        period: 1,
        metadata: KPIMetadata::make(scheduledSeconds: 27000, scheduledDays: 1, excludedDays: 2),
    )->toArray());
});

it('does not double-count a holiday that falls on a weekend', function () {
    // Sat Jan 4 is already unscheduled
    Holiday::create(['name' => 'Weekend Holiday', 'date' => '2025-01-04']);

    // Fri Jan 3 + Sat Jan 4 (weekend, also holiday) + Sun Jan 5 (weekend) + Mon Jan 6
    $kpi = KPI::calculate(Carbon::parse('2025-01-03 08:00'), Carbon::parse('2025-01-06 17:00'));

    // Sat is unscheduled (skipped before holiday check), Sun is unscheduled, holiday doesn't apply
    expect($kpi->metadata->unscheduledDays)->toBe(2)
        ->and($kpi->metadata->excludedDays)->toBe(0)
        ->and($kpi->metadata->scheduledDays)->toBe(2);
});

// --- Custom exclude dates ---

it('excludes custom dates from calculation', function () {
    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-01 08:00'),
        end: Carbon::parse('2025-01-03 15:30'),
        excludeDates: [Carbon::parse('2025-01-02')],
    );

    expect($kpi->metadata->excludedDays)->toBe(1)
        ->and($kpi->metadata->scheduledDays)->toBe(2)
        ->and($kpi->seconds)->toBe(59400);
});

it('combines custom exclude dates with holidays', function () {
    Holiday::create(['name' => 'Holiday', 'date' => '2025-01-01']);

    $kpi = KPI::calculate(
        start: Carbon::parse('2025-01-01 08:00'),
        end: Carbon::parse('2025-01-03 15:30'),
        excludeDates: [Carbon::parse('2025-01-02')],
    );

    // Both Jan 1 (holiday) and Jan 2 (custom) excluded, only Fri Jan 3 remains
    expect($kpi->metadata->excludedDays)->toBe(2)
        ->and($kpi->metadata->scheduledDays)->toBe(1)
        ->and($kpi->seconds)->toBe(27000);
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

    expect($kpi->seconds)->toBe(21600)
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

    expect($kpi->seconds)->toBe(32400)
        ->and($kpi->metadata->scheduledDays)->toBe(1)
        ->and($kpi->metadata->unscheduledDays)->toBe(1);
});

// --- Edge cases ---

it('returns zero when start equals end', function () {
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 10:00'), Carbon::parse('2025-01-01 10:00'));

    expect($kpi->seconds)->toBe(0)
        ->and($kpi->period)->toBe(0.0);
});

it('returns zero when entire range falls outside work hours', function () {
    // 5:00 AM to 7:00 AM, before work starts at 8:00
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 05:00'), Carbon::parse('2025-01-01 07:00'));

    expect($kpi->seconds)->toBe(0)
        ->and($kpi->metadata->scheduledDays)->toBe(1);
});

it('handles range spanning only weekends', function () {
    // Sat Jan 4 to Sun Jan 5
    $kpi = KPI::calculate(Carbon::parse('2025-01-04 08:00'), Carbon::parse('2025-01-05 17:00'));

    expect($kpi->seconds)->toBe(0)
        ->and($kpi->metadata->unscheduledDays)->toBe(2)
        ->and($kpi->metadata->scheduledDays)->toBe(0);
});

it('clamps start time to work start when arriving early', function () {
    // Arrive at 6:00, work starts at 8:00, leave at 10:00 → 2 hours of work
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 06:00'), Carbon::parse('2025-01-01 10:00'));

    expect($kpi->seconds)->toBe(7200);
});

it('clamps end time to work end when leaving late', function () {
    // Arrive at 15:00, work ends at 17:00 on wednesday, leave at 19:00 → 2 hours
    $kpi = KPI::calculate(Carbon::parse('2025-01-01 15:00'), Carbon::parse('2025-01-01 19:00'));

    expect($kpi->seconds)->toBe(7200);
});

it('excludes a recurring holiday on the same month/day in each year of the range', function () {
    // Labour Day May 1 — recurring annually
    RecurringHoliday::create(['name' => 'Labour Day', 'month' => 5, 'day' => 1]);

    // Two consecutive years, calc on May 1 in each
    $kpi2025 = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-02 17:00'));
    $kpi2026 = KPI::calculate(Carbon::parse('2026-04-30 08:00'), Carbon::parse('2026-05-02 17:00'));

    // 2025-05-01 is a Thursday, 2026-05-01 is a Friday — both scheduled days
    expect($kpi2025->metadata->excludedDays)->toBe(1)
        ->and($kpi2026->metadata->excludedDays)->toBe(1);
});

it('skips a recurring Feb 29 in non-leap years and applies it in leap years', function () {
    RecurringHoliday::create(['name' => 'Leap Day', 'month' => 2, 'day' => 29]);

    // 2024 is leap (Feb 29 = Thursday, scheduled). 2025 is not.
    $kpi2024 = KPI::calculate(Carbon::parse('2024-02-28 08:00'), Carbon::parse('2024-03-01 17:00'));
    $kpi2025 = KPI::calculate(Carbon::parse('2025-02-27 08:00'), Carbon::parse('2025-02-28 17:00'));

    expect($kpi2024->metadata->excludedDays)->toBe(1)   // Feb 29 2024 excluded
        ->and($kpi2025->metadata->excludedDays)->toBe(0); // no Feb 29 in 2025
});

it('ignores a recurring holiday whose effective_until is before the range', function () {
    RecurringHoliday::create([
        'name' => 'Retired',
        'month' => 5,
        'day' => 1,
        'effective_until' => '2024-12-31',
    ]);

    $kpi = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-02 17:00'));

    expect($kpi->metadata->excludedDays)->toBe(0);
});

it('ignores a recurring holiday whose effective_from is after the range', function () {
    RecurringHoliday::create([
        'name' => 'Future',
        'month' => 5,
        'day' => 1,
        'effective_from' => '2030-01-01',
    ]);

    $kpi = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-02 17:00'));

    expect($kpi->metadata->excludedDays)->toBe(0);
});

it('combines one-off and recurring holidays', function () {
    Holiday::create(['name' => 'One-off', 'date' => '2025-05-01']);
    RecurringHoliday::create(['name' => 'Recurring', 'month' => 5, 'day' => 2]);

    $kpi = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-05 17:00'));

    // May 1 (Thu, one-off) + May 2 (Fri, recurring) excluded; May 3-4 unscheduled weekend
    expect($kpi->metadata->excludedDays)->toBe(2);
});

// --- Substitute day resolution ---

it('does not substitute when observes_substitute is false by default', function () {
    config(['kpi.substitute' => [Day::SUNDAY->value]]);

    // Sun 2025-05-04 — non-working in default Mon-Fri schedule
    Holiday::create(['name' => 'Sun Holiday', 'date' => '2025-05-04']);

    $kpi = KPI::calculate(Carbon::parse('2025-05-02 08:00'), Carbon::parse('2025-05-06 17:00'));

    // No substitute → Monday May 5 stays scheduled (not excluded)
    expect($kpi->metadata->excludedDays)->toBe(0);
});

it('does not substitute when the day-of-week is not in kpi.substitute', function () {
    config(['kpi.substitute' => []]);   // empty config

    Holiday::create(['name' => 'Sun Holiday', 'date' => '2025-05-04', 'observes_substitute' => true]);

    $kpi = KPI::calculate(Carbon::parse('2025-05-02 08:00'), Carbon::parse('2025-05-06 17:00'));

    // observes_substitute=true but Sunday not eligible → no substitute
    expect($kpi->metadata->excludedDays)->toBe(0);
});

it('substitutes Sunday holiday to Monday under Sat-Sun config', function () {
    config(['kpi.substitute' => [Day::SUNDAY->value]]);
    // Default schedule is Mon-Fri working.

    Holiday::create(['name' => 'Sun', 'date' => '2025-05-04', 'observes_substitute' => true]);

    $kpi = KPI::calculate(Carbon::parse('2025-05-02 08:00'), Carbon::parse('2025-05-06 17:00'));

    // Sun substituted to Mon May 5 → Mon excluded
    expect($kpi->metadata->excludedDays)->toBe(1);
});

it('substitutes Saturday holiday to Sunday under Kelantan/Terengganu config', function () {
    config(['kpi.substitute' => [Day::SATURDAY->value]]);

    // Sun-Thu schedule; Fri-Sat off.
    $schedules = collect([
        Day::SUNDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::MONDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::TUESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::WEDNESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::THURSDAY->value => WorkSchedule::parse(['8:00', '17:00']),
    ]);

    // Sat 2025-05-03
    Holiday::create(['name' => 'Sat', 'date' => '2025-05-03', 'observes_substitute' => true]);

    $kpi = KPI::calculate(
        Carbon::parse('2025-05-02 08:00'),  // Fri (off)
        Carbon::parse('2025-05-06 17:00'),  // Tue
        schedules: $schedules,
    );

    // Sat substituted to Sun May 4 → Sun excluded
    expect($kpi->metadata->excludedDays)->toBe(1);
});

it('substitutes Friday holiday past Saturday to Sunday under Kedah config', function () {
    config(['kpi.substitute' => [Day::FRIDAY->value]]);

    $schedules = collect([
        Day::SUNDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::MONDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::TUESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::WEDNESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::THURSDAY->value => WorkSchedule::parse(['8:00', '17:00']),
    ]);

    // Fri 2025-05-02
    Holiday::create(['name' => 'Fri', 'date' => '2025-05-02', 'observes_substitute' => true]);

    $kpi = KPI::calculate(
        Carbon::parse('2025-04-30 08:00'),  // Wed
        Carbon::parse('2025-05-06 17:00'),  // Tue
        schedules: $schedules,
    );

    // Fri → skip Sat (off) → Sun May 4 (working in Kedah schedule) → Sun excluded
    expect($kpi->metadata->excludedDays)->toBe(1);
});

it('collapses collision when Sat substitutes onto Sunday holiday in Terengganu', function () {
    config(['kpi.substitute' => [Day::SATURDAY->value]]);

    $schedules = collect([
        Day::SUNDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::MONDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::TUESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::WEDNESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::THURSDAY->value => WorkSchedule::parse(['8:00', '17:00']),
    ]);

    Holiday::create(['name' => 'Sat', 'date' => '2025-05-03', 'observes_substitute' => true]);
    Holiday::create(['name' => 'Sun', 'date' => '2025-05-04']);   // observes_substitute=false (Sun not eligible anyway)

    $kpi = KPI::calculate(
        Carbon::parse('2025-05-02 08:00'),
        Carbon::parse('2025-05-06 17:00'),
        schedules: $schedules,
    );

    // Sat sub → Sun. Sun raw also there. Collision → Sun excluded ONCE. Mon stays scheduled.
    expect($kpi->metadata->excludedDays)->toBe(1);
});

it('does not substitute when the raw day is a working day, even if in kpi.substitute', function () {
    // Misconfig: someone put Wednesday (a working day) in substitute config.
    config(['kpi.substitute' => [Day::WEDNESDAY->value]]);

    Holiday::create(['name' => 'Wed', 'date' => '2025-04-30', 'observes_substitute' => true]);   // Wed

    $kpi = KPI::calculate(Carbon::parse('2025-04-29 08:00'), Carbon::parse('2025-05-02 17:00'));

    // Wed is working → guard blocks substitute → Wed itself excluded (1), not Thu
    expect($kpi->metadata->excludedDays)->toBe(1);
});

it('substitutes a recurring holiday occurrence per year independently', function () {
    config(['kpi.substitute' => [Day::SUNDAY->value]]);

    // National Day Aug 31 — recurring annually, observes substitute.
    RecurringHoliday::create([
        'name' => 'National Day',
        'month' => 8,
        'day' => 31,
        'observes_substitute' => true,
    ]);

    // 2025-08-31 is a Sunday — substituted to Mon Sep 1.
    // 2026-08-31 is a Monday — no substitute needed (Mon is working).
    $kpi2025 = KPI::calculate(Carbon::parse('2025-08-29 08:00'), Carbon::parse('2025-09-02 17:00'));
    $kpi2026 = KPI::calculate(Carbon::parse('2026-08-30 08:00'), Carbon::parse('2026-09-01 17:00'));

    // 2025: Aug 31 (Sun, unscheduled) + Sep 1 (Mon, excluded via substitute) → excluded = 1
    // 2026: Aug 31 (Mon, excluded directly) → excluded = 1
    expect($kpi2025->metadata->excludedDays)->toBe(1)
        ->and($kpi2026->metadata->excludedDays)->toBe(1);
});

// --- Config published by older versions ---

it('calculates with a config published before recurring holidays existed', function () {
    config()->set('kpi.models', ['movement' => Movement::class, 'holiday' => Holiday::class]);

    // Mon 8:00 → 17:00 = 9 working hours
    $kpi = KPI::calculate(Carbon::parse('2024-01-01 08:00'), Carbon::parse('2024-01-01 17:00'));

    expect($kpi->seconds)->toBe(32400);
});

// --- Schedule input and boundaries ---

it('falls back to the configured schedule when given an empty collection of schedules', function () {
    $kpi = KPI::calculate(Carbon::parse('2024-01-01 08:00'), Carbon::parse('2024-01-01 17:00'), schedules: collect());

    expect($kpi->seconds)->toBe(32400);
});

it('does not count the seconds of a mid-day start past the end of the work day', function () {
    // Mon 09:15:30 → 17:00:00 = 464.5 minutes; Tue ends at 08:00 before work starts
    $kpi = KPI::calculate(Carbon::parse('2024-01-01 09:15:30'), Carbon::parse('2024-01-02 08:00'));

    expect($kpi->seconds)->toBe(27870);
});

// --- Time zone and exact seconds ---

it('applies the schedule in kpi.timezone', function () {
    config()->set('kpi.timezone', 'Asia/Kuala_Lumpur');

    expect(KPI::calculate(Carbon::parse('2025-01-01 00:00', 'UTC'), Carbon::parse('2025-01-01 09:00', 'UTC'))->seconds)
        ->toBe(32400);
});

it('does not zone-convert excluded dates', function () {
    config()->set('kpi.timezone', 'Asia/Kuala_Lumpur');

    $kpi = KPI::calculate('2025-01-02 00:00', '2025-01-02 09:00', excludeDates: [Carbon::parse('2025-01-02 20:00', 'UTC')]);

    expect($kpi->metadata->excludedDays)->toBe(1);
});

it('uses the real window length on a DST day', function () {
    config()->set('kpi.timezone', 'Europe/London');
    $schedule = [0 => WorkSchedule::parse(['00:30', '03:30'])];

    $kpi = KPI::calculate('2026-03-29 00:00', '2026-03-29 12:00', schedules: $schedule);

    expect($kpi->seconds)->toBe(7200)
        ->and($kpi->metadata->scheduledSeconds)->toBe(7200)
        ->and($kpi->period)->toBe(1.0);
});

it('survives a zero-length window on a DST day', function () {
    config()->set('kpi.timezone', 'Europe/London');
    $schedule = [0 => WorkSchedule::parse(['01:00', '02:00'])];

    $kpi = KPI::calculate('2026-03-29 00:00', '2026-03-29 12:00', schedules: $schedule);

    expect($kpi->seconds)->toBe(0)->and($kpi->period)->toBe(0.0);
});

it('counts exact seconds over more than two years', function () {
    $schedule = collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => WorkSchedule::parse(['8:00', '9:00'])])->all();

    $kpi = KPI::calculate('2025-01-01 00:00', '2027-01-01 00:00', schedules: $schedule);

    expect($kpi->seconds)->toBe(730 * 3600)->and($kpi->period)->toBe(730.0);
});

it('counts 10 minutes as exactly 600 seconds', function () {
    expect(KPI::calculate('2025-01-01 08:00', '2025-01-01 08:10')->seconds)->toBe(600);
});

it('finds a holiday on the first day of the range when the bound has a time', function () {
    Holiday::create(['name' => 'H', 'date' => '2025-01-01']);

    expect(KPI::calculate('2025-01-01 13:00', '2025-01-01 15:00')->metadata->excludedDays)->toBe(1);
});

it('finds a holiday on the last day of the range when the bound has a time', function () {
    Holiday::create(['name' => 'H', 'date' => '2025-01-02']);

    expect(KPI::calculate('2025-01-02 08:00', '2025-01-02 15:00')->metadata->excludedDays)->toBe(1);
});

it('finds a recurring holiday on the first and last day of the range when the bounds have a time', function () {
    RecurringHoliday::create(['name' => 'Labour Day', 'month' => 5, 'day' => 1]);

    expect(KPI::calculate('2025-05-01 13:00', '2025-05-01 15:00')->metadata->excludedDays)->toBe(1)
        ->and(KPI::calculate('2025-04-30 13:00', '2025-05-01 15:00')->metadata->excludedDays)->toBe(1);
});

it('finds a recurring holiday on the last day when kpi.timezone is ahead of the PHP default time zone', function () {
    config()->set('kpi.timezone', 'Asia/Kuala_Lumpur');
    RecurringHoliday::create(['name' => 'New Year', 'month' => 1, 'day' => 1]);

    expect(KPI::calculate('2025-01-01 02:00', '2025-01-01 03:00')->metadata->excludedDays)->toBe(1);
});

it('looks back 7 days when the range starts with a time', function () {
    config()->set('kpi.substitute', [Day::SUNDAY->value]);
    Holiday::create(['name' => 'Sun', 'date' => '2025-05-04', 'observes_substitute' => true]);

    expect(KPI::calculate('2025-05-05 08:00', '2025-05-05 17:00')->metadata->excludedDays)->toBe(1);
});
