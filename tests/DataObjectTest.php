<?php

use Hasyirin\KPI\Data\Hour;
use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Data\WorkSchedule;

// --- Hour ---

it('parses hour from string', function () {
    $hour = Hour::parse('08:30');

    expect($hour->hour)->toBe(8)
        ->and($hour->minute)->toBe(30);
});

it('creates hour with make', function () {
    $hour = Hour::make(14, 45);

    expect($hour->hour)->toBe(14)
        ->and($hour->minute)->toBe(45);
});

it('creates hour from total minutes', function () {
    $hour = Hour::fromMinutes(510); // 8 hours 30 minutes

    expect($hour->hour)->toBe(8)
        ->and($hour->minute)->toBe(30);
});

it('converts hour to total minutes', function () {
    expect(Hour::make(8, 30)->minutes())->toBe(510)
        ->and(Hour::make(17, 0)->minutes())->toBe(1020)
        ->and(Hour::make(0, 0)->minutes())->toBe(0);
});

it('defaults minute to zero', function () {
    $hour = Hour::make(9);

    expect($hour->minute)->toBe(0)
        ->and($hour->minutes())->toBe(540);
});

it('rejects an hour outside 0 to 23', function (int $hour) {
    expect(fn () => Hour::make($hour))->toThrow(InvalidArgumentException::class);
})->with([-1, 24]);

it('rejects a minute outside 0 to 59', function (int $minute) {
    expect(fn () => Hour::make(8, $minute))->toThrow(InvalidArgumentException::class);
})->with([-1, 60]);

it('rejects 24:00', function () {
    expect(fn () => Hour::parse('24:00'))->toThrow(InvalidArgumentException::class);
});

// --- WorkSchedule ---

it('parses work schedule from array', function () {
    $schedule = WorkSchedule::parse(['8:00', '17:00']);

    expect($schedule->start->hour)->toBe(8)
        ->and($schedule->start->minute)->toBe(0)
        ->and($schedule->end->hour)->toBe(17)
        ->and($schedule->end->minute)->toBe(0);
});

it('calculates available minutes in schedule', function () {
    expect(WorkSchedule::parse(['8:00', '17:00'])->minutes())->toBe(540)
        ->and(WorkSchedule::parse(['8:00', '15:30'])->minutes())->toBe(450)
        ->and(WorkSchedule::parse(['9:00', '12:00'])->minutes())->toBe(180);
});

it('creates work schedule with make', function () {
    $schedule = WorkSchedule::make(Hour::make(9), Hour::make(17));

    expect($schedule->minutes())->toBe(480);
});

it('rejects a work schedule that does not end after it starts', function () {
    expect(fn () => WorkSchedule::parse(['8:30', '8:00']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkSchedule::parse(['8:00', '8:00']))->toThrow(InvalidArgumentException::class);
});

// --- KPIMetadata ---

it('creates metadata with defaults', function () {
    $meta = KPIMetadata::make();

    expect($meta->scheduledSeconds)->toBe(0)
        ->and($meta->scheduledDays)->toBe(0)
        ->and($meta->unscheduledDays)->toBe(0)
        ->and($meta->excludedDays)->toBe(0);
});

it('converts metadata to array', function () {
    $meta = KPIMetadata::make(scheduledSeconds: 32400, scheduledDays: 1, unscheduledDays: 2, excludedDays: 3);

    expect($meta->toArray())->toBe([
        'scheduledSeconds' => 32400,
        'scheduledDays' => 1,
        'unscheduledDays' => 2,
        'excludedDays' => 3,
    ]);
});

it('serializes metadata to json', function () {
    $meta = KPIMetadata::make(scheduledSeconds: 6000);

    expect(json_encode($meta))->toBe('{"scheduledSeconds":6000,"scheduledDays":0,"unscheduledDays":0,"excludedDays":0}');
});

it('compares metadata equality', function () {
    $a = KPIMetadata::make(scheduledSeconds: 32400, scheduledDays: 1);
    $b = KPIMetadata::make(scheduledSeconds: 32400, scheduledDays: 1);
    $c = KPIMetadata::make(scheduledSeconds: 32400, scheduledDays: 2);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

// --- KPIData ---

it('creates KPIData with defaults', function () {
    $data = KPIData::make();

    expect($data->seconds)->toBe(0)
        ->and($data->period)->toBe(0.0)
        ->and($data->metadata)->toBeInstanceOf(KPIMetadata::class);
});

it('converts KPIData to array', function () {
    $data = KPIData::make(seconds: 3600, period: 0.1111);

    expect($data->toArray())->toBe([
        'seconds' => 3600,
        'period' => 0.1111,
        'metadata' => [
            'scheduledSeconds' => 0,
            'scheduledDays' => 0,
            'unscheduledDays' => 0,
            'excludedDays' => 0,
        ],
    ]);
});

it('serializes KPIData to json', function () {
    $data = KPIData::make(seconds: 3600, period: 0.5);
    $decoded = json_decode(json_encode($data), true);

    expect($decoded['seconds'])->toBe(3600)
        ->and($decoded['period'])->toEqual(0.5);
});

it('compares KPIData equality', function () {
    $a = KPIData::make(seconds: 3600, period: 0.1111);
    $b = KPIData::make(seconds: 3600, period: 0.1111);
    $c = KPIData::make(seconds: 7200, period: 0.2222);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

it('compares seconds exactly', function () {
    expect(KPIData::make(seconds: 3600)->equals(KPIData::make(seconds: 3601)))->toBeFalse();
});

it('tolerates float noise in period when comparing', function () {
    expect(KPIData::make(period: 0.1111)->equals(KPIData::make(period: 0.11110001)))->toBeTrue();
});

it('KPIData equality checks metadata too', function () {
    $a = KPIData::make(seconds: 3600, period: 0.5, metadata: KPIMetadata::make(scheduledSeconds: 32400));
    $b = KPIData::make(seconds: 3600, period: 0.5, metadata: KPIMetadata::make(scheduledSeconds: 27000));

    expect($a->equals($b))->toBeFalse();
});

it('reports minutes and hours as floats and interval cascaded', function () {
    $kpi = KPIData::make(seconds: 5400);

    expect($kpi->minutes())->toBe(90.0)
        ->and($kpi->hours())->toBe(1.5)
        ->and($kpi->interval()->forHumans(['parts' => 2]))->toBe('1 hour 30 minutes');
});
