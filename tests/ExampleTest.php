<?php

use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Facades\KPI;
use Illuminate\Support\Carbon;

function calc(Carbon|string $start, Carbon|string $end): KPIData
{
    return KPI::calculate(Carbon::parse($start), Carbon::parse($end));
}

it('working after hours should yield 0 work hours.', function () {
    expect(calc('2025-01-01 20:00', '2025-01-01 22:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 0,
        'period' => 0,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 hour of work on wednesday', function () {
    expect(calc('2025-01-01 08:00', '2025-01-01 09:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 3600,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('a full day of work on wednesday', function () {
    expect(calc('2025-01-01 08:00', '2025-01-01 17:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 32400,
        'period' => 1.0,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('a full day of work on thursday', function () {
    expect(calc('2025-01-02 08:00', '2025-01-02 17:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 32400,
        'period' => 1,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('a full day of work on friday', function () {
    expect(calc('2025-01-03 08:00', '2025-01-03 15:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 27000,
        'period' => 1,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 27000,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 day of work done on saturday', function () {
    expect(calc('2025-01-04 08:00', '2025-01-04 17:00'))->toMatchArray(KPIData::make(...[
        'metadata' => KPIMetadata::make(...[
            'unscheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 day of work done on sunday', function () {
    expect(calc('2025-01-05 08:00', '2025-01-05 17:00'))->toMatchArray(KPIData::make(...[
        'metadata' => KPIMetadata::make(...[
            'unscheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work at start', function () {
    expect(calc('2025-01-01 08:30', '2025-01-01 09:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work, 30 minutes before starting', function () {
    expect(calc('2025-01-01 07:30', '2025-01-01 08:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work, 1 hour before starting', function () {
    expect(calc('2025-01-01 07:00', '2025-01-01 08:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work, 1 hour 30 minutes before starting', function () {
    expect(calc('2025-01-01 06:30', '2025-01-01 08:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 hour of work, at start', function () {
    expect(calc('2025-01-01 08:00', '2025-01-01 09:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 3600,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 hour of work, 30 minutes before starting', function () {
    expect(calc('2025-01-01 07:30', '2025-01-01 09:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 3600,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 hour of work, 1 hour before starting', function () {
    expect(calc('2025-01-01 07:00', '2025-01-01 09:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 3600,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 hour of work, 1 hour and 30 minutes before starting', function () {
    expect(calc('2025-01-01 06:30', '2025-01-01 09:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 3600,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work, at ending', function () {
    expect(calc('2025-01-01 16:30', '2025-01-01 17:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work, 30 minutes past ending', function () {
    expect(calc('2025-01-01 16:30', '2025-01-01 17:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work, 1 hour past ending', function () {
    expect(calc('2025-01-01 16:30', '2025-01-01 18:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('30 minutes of work, 1 hour 30 minutes past ending', function () {
    expect(calc('2025-01-01 16:30', '2025-01-01 18:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0556,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 hour of work spanning 2 days', function () {
    expect(calc('2025-01-01 16:30', '2025-01-02 08:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 3600,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('2 hour of work spanning 2 days', function () {
    expect(calc('2025-01-01 16:00', '2025-01-02 09:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 7200,
        'period' => 0.2222,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('9 hours of work spanning 2 days', function () {
    expect(calc('2025-01-01 12:30', '2025-01-02 12:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 32400,
        'period' => 1,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('13 hours and 30 minutes of work spanning 2 days', function () {
    expect(calc('2025-01-01 10:00', '2025-01-02 14:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 48600,
        'period' => 1.5,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('18 hours of work spanning 2 days', function () {
    expect(calc('2025-01-01 08:00', '2025-01-02 17:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 64800,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('18 hours and 30 minutes of work spanning 2 days, starting 30 minutes before work hour', function () {
    expect(calc('2025-01-01 07:30', '2025-01-02 17:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 64800,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('18 hours and 30 minutes of work spanning 2 days, ending 30 minutes after work hour', function () {
    expect(calc('2025-01-01 08:00', '2025-01-02 17:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 64800,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('19 hours of work spanning 2 days, starting/ending 30 minutes before/after work hour', function () {
    expect(calc('2025-01-01 07:30', '2025-01-02 17:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 64800,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('20 hours of work spanning 2 days, starting/ending 1 hour before/after work hour', function () {
    expect(calc('2025-01-01 07:00', '2025-01-02 18:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 64800,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 64800,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('30 minutes of work spanning 2 days, with weekends in between', function () {
    expect(calc('2025-01-03 15:00', '2025-01-06 8:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 1800,
        'period' => 0.0667,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 59400,
            'unscheduledDays' => 2,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('1 hour of work spanning 4 days including weekends in between work days', function () {
    expect(calc('2025-01-03 15:00', '2025-01-06 8:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 3600,
        'period' => 0.1222,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 59400,
            'unscheduledDays' => 2,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('2 full days of work starting on friday which has fewer work hours spanning 4 days including weekends in between work days', function () {
    expect(calc('2025-01-03 08:00', '2025-01-06 17:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 59400,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 59400,
            'unscheduledDays' => 2,
            'scheduledDays' => 2,
        ]),
    ])->toArray());
});

it('2 and a half day of work starting on friday which has fewer work hours spanning 5 days including weekends in between work days', function () {
    expect(calc('2025-01-03 08:00', '2025-01-07 12:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 75600,
        'period' => 2.5,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 91800,
            'unscheduledDays' => 2,
            'scheduledDays' => 3,
        ]),
    ])->toArray());
});

it('One whole week', function () {
    expect(calc('2025-01-06 08:00', '2025-01-10 15:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 156600,
        'period' => 5,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 156600,
            'scheduledDays' => 5,
        ]),
    ])->toArray());
});

it('One whole week with 2 weekends', function () {
    expect(calc('2025-01-09 08:00', '2025-01-15 17:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 156600,
        'period' => 5,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 156600,
            'unscheduledDays' => 2,
            'scheduledDays' => 5,
        ]),
    ])->toArray());
});

it('Two weeks', function () {
    expect(calc('2025-01-06 08:00', '2025-01-17 15:30'))->toMatchArray(KPIData::make(...[
        'seconds' => 313200,
        'period' => 10,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 313200,
            'unscheduledDays' => 2,
            'scheduledDays' => 10,
        ]),
    ])->toArray());
});

it('1 day of work during weekends, ending 2 hours of work on work day', function () {
    expect(calc('2025-01-12 08:00', '2025-01-13 10:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 7200,
        'period' => 0.2222,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 32400,
            'unscheduledDays' => 1,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});

it('1 day of work during workdays, ending 2 hours of work on weekends', function () {
    expect(calc('2025-01-10 08:00', '2025-01-11 10:00'))->toMatchArray(KPIData::make(...[
        'seconds' => 27000,
        'period' => 1,
        'metadata' => KPIMetadata::make(...[
            'scheduledSeconds' => 27000,
            'unscheduledDays' => 1,
            'scheduledDays' => 1,
        ]),
    ])->toArray());
});
