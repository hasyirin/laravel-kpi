<?php

use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Facades\KPI;
use Illuminate\Support\Carbon;

it('a full day of work on wednesday', function () {
    $start = Carbon::parse('2025-01-01 08:00');
    $end = Carbon::parse('2025-01-01 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 540,
        'hours' => 9,
        'period' => 1.0,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' =>0,
        ]),
    ])));
});

it('a full day of work on thursday', function () {
    $start = Carbon::parse('2025-01-02 08:00');
    $end = Carbon::parse('2025-01-02 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 540.0000,
        'hours' => 9.0000,
        'period' => 1.0,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('a full day of work on friday', function () {
    $start = Carbon::parse('2025-01-03 08:00');
    $end = Carbon::parse('2025-01-03 15:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 450,
        'hours' => 7.5,
        'period' => 1.0,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 450,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('1 day of work done on saturday', function () {
    $start = Carbon::parse('2025-01-04 08:00');
    $end = Carbon::parse('2025-01-04 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 0,
        'hours' => 0,
        'period' => 0,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 0,
            'unscheduled' => 1,
            'scheduled' => 0,
            'excluded' => 0,
        ]),
    ])));
});

it('1 day of work done on sunday', function () {
    $start = Carbon::parse('2025-01-05 08:00');
    $end = Carbon::parse('2025-01-05 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 0,
        'hours' => 0,
        'period' => 0,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 0,
            'unscheduled' => 1,
            'scheduled' => 0,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work at start', function () {
    $start = Carbon::parse('2025-01-01 08:30');
    $end = Carbon::parse('2025-01-01 09:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work, 30 minutes before starting', function () {
    $start = Carbon::parse('2025-01-01 07:30');
    $end = Carbon::parse('2025-01-01 08:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work, 1 hour before starting', function () {
    $start = Carbon::parse('2025-01-01 07:00');
    $end = Carbon::parse('2025-01-01 08:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work, 1 hour 30 minutes before starting', function () {
    $start = Carbon::parse('2025-01-01 06:30');
    $end = Carbon::parse('2025-01-01 08:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('1 hour of work, at start', function () {
    $start = Carbon::parse('2025-01-01 08:00');
    $end = Carbon::parse('2025-01-01 09:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 60,
        'hours' => 1,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('1 hour of work, 30 minutes before starting', function () {
    $start = Carbon::parse('2025-01-01 07:30');
    $end = Carbon::parse('2025-01-01 09:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 60,
        'hours' => 1,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('1 hour of work, 1 hour before starting', function () {
    $start = Carbon::parse('2025-01-01 07:00');
    $end = Carbon::parse('2025-01-01 09:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 60,
        'hours' => 1,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('1 hour of work, 1 hour and 30 minutes before starting', function () {
    $start = Carbon::parse('2025-01-01 06:30');
    $end = Carbon::parse('2025-01-01 09:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 60,
        'hours' => 1,
        'period' => 0.1111,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work, at ending', function () {
    $start = Carbon::parse('2025-01-01 16:30');
    $end = Carbon::parse('2025-01-01 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work, 30 minutes past ending', function () {
    $start = Carbon::parse('2025-01-01 16:30');
    $end = Carbon::parse('2025-01-01 17:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work, 1 hour past ending', function () {
    $start = Carbon::parse('2025-01-01 16:30');
    $end = Carbon::parse('2025-01-01 18:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work, 1 hour 30 minutes past ending', function () {
    $start = Carbon::parse('2025-01-01 16:30');
    $end = Carbon::parse('2025-01-01 18:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0555,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 0,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('1 hour of work spanning 2 days', function () {
    $start = Carbon::parse('2025-01-01 16:30');
    $end = Carbon::parse('2025-01-02 08:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 60,
        'hours' => 1,
        'period' => 0.111,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('2 hour of work spanning 2 days', function () {
    $start = Carbon::parse('2025-01-01 16:00');
    $end = Carbon::parse('2025-01-02 09:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 120,
        'hours' => 2,
        'period' => 0.2222,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('9 hours of work spanning 2 days', function () {
    $start = Carbon::parse('2025-01-01 12:30');
    $end = Carbon::parse('2025-01-02 12:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 540,
        'hours' => 9,
        'period' => 1,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('13 hours and 30 minutes of work spanning 2 days', function () {
    $start = Carbon::parse('2025-01-01 10:00');
    $end = Carbon::parse('2025-01-02 14:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 810,
        'hours' => 13.5,
        'period' => 1.4999,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('18 hours of work spanning 2 days', function () {
    $start = Carbon::parse('2025-01-01 08:00');
    $end = Carbon::parse('2025-01-02 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 1080,
        'hours' => 18,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('18 hours and 30 minutes of work spanning 2 days, starting 30 minutes before work hour', function () {
    $start = Carbon::parse('2025-01-01 07:30');
    $end = Carbon::parse('2025-01-02 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 1080,
        'hours' => 18,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('18 hours and 30 minutes of work spanning 2 days, ending 30 minutes after work hour', function () {
    $start = Carbon::parse('2025-01-01 08:00');
    $end = Carbon::parse('2025-01-02 17:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 1080,
        'hours' => 18,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('19 hours of work spanning 2 days, starting/ending 30 minutes before/after work hour', function () {
    $start = Carbon::parse('2025-01-01 07:30');
    $end = Carbon::parse('2025-01-02 17:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 1080,
        'hours' => 18,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('20 hours of work spanning 2 days, starting/ending 1 hour before/after work hour', function () {
    $start = Carbon::parse('2025-01-01 07:00');
    $end = Carbon::parse('2025-01-02 18:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 1080,
        'hours' => 18,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1080,
            'unscheduled' => 0,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('30 minutes of work spanning 2 days, with weekends in between', function () {
    $start = Carbon::parse('2025-01-03 15:00');
    $end = Carbon::parse('2025-01-06 8:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 30,
        'hours' => 0.5,
        'period' => 0.0666,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 990,
            'unscheduled' => 2,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('1 hour of work spanning 4 days including weekends in between work days', function () {
    $start = Carbon::parse('2025-01-03 15:00');
    $end = Carbon::parse('2025-01-06 8:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 60,
        'hours' => 1,
        'period' => 0.1221,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 990,
            'unscheduled' => 2,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('2 full days of work starting on friday which has fewer work hours spanning 4 days including weekends in between work days', function () {
    $start = Carbon::parse('2025-01-03 08:00');
    $end = Carbon::parse('2025-01-06 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 990,
        'hours' => 16.5,
        'period' => 2,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 990,
            'unscheduled' => 2,
            'scheduled' => 2,
            'excluded' => 0,
        ]),
    ])));
});

it('2 and a half day of work starting on friday which has fewer work hours spanning 5 days including weekends in between work days', function () {
    $start = Carbon::parse('2025-01-03 08:00');
    $end = Carbon::parse('2025-01-07 12:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 1260,
        'hours' => 21,
        'period' => 2.5,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 1530,
            'unscheduled' => 2,
            'scheduled' => 3,
            'excluded' => 0,
        ]),
    ])));
});

it('One whole week', function () {
    $start = Carbon::parse('2025-01-06 08:00');
    $end = Carbon::parse('2025-01-10 15:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 2610,
        'hours' => 43.5,
        'period' => 5,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 2610,
            'unscheduled' => 0,
            'scheduled' => 5,
            'excluded' => 0,
        ]),
    ])));
});

it('One whole week with 2 weekends', function () {
    $start = Carbon::parse('2025-01-09 08:00');
    $end = Carbon::parse('2025-01-15 17:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 2610,
        'hours' => 43.5,
        'period' => 5,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 2610,
            'unscheduled' => 2,
            'scheduled' => 5,
            'excluded' => 0,
        ]),
    ])));
});

it('Two weeks', function () {
    $start = Carbon::parse('2025-01-06 08:00');
    $end = Carbon::parse('2025-01-17 15:30');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 5220,
        'hours' => 87,
        'period' => 10,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 5220,
            'unscheduled' => 2,
            'scheduled' => 10,
            'excluded' => 0,
        ]),
    ])));
});

it('1 day of work during weekends, ending 2 hours of work on work day', function () {
    $start = Carbon::parse('2025-01-12 08:00');
    $end = Carbon::parse('2025-01-13 10:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 120,
        'hours' => 2,
        'period' => 0.2222,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 540,
            'unscheduled' => 1,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});

it('1 day of work during workdays, ending 2 hours of work on weekends', function () {
    $start = Carbon::parse('2025-01-10 08:00');
    $end = Carbon::parse('2025-01-11 10:00');

    $data = KPI::calculate($start, $end);

    $this->assertTrue($data->equals(KPIData::make(...[
        'minutes' => 450,
        'hours' => 7.5,
        'period' => 1,
        'metadata' => KPIMetadata::make(...[
            'minutes' => 450,
            'unscheduled' => 1,
            'scheduled' => 1,
            'excluded' => 0,
        ]),
    ])));
});
