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

// --- KPIMetadata ---

it('creates metadata with defaults', function () {
    $meta = KPIMetadata::make();

    expect($meta->minutes)->toBe(0)
        ->and($meta->unscheduled)->toBe(0)
        ->and($meta->scheduled)->toBe(0)
        ->and($meta->excluded)->toBe(0);
});

it('converts metadata to array', function () {
    $meta = KPIMetadata::make(minutes: 540, scheduled: 1, unscheduled: 2, excluded: 3);

    expect($meta->toArray())->toBe([
        'minutes' => 540,
        'unscheduled' => 2,
        'scheduled' => 1,
        'excluded' => 3,
    ]);
});

it('serializes metadata to json', function () {
    $meta = KPIMetadata::make(minutes: 100);

    expect(json_encode($meta))->toBe('{"minutes":100,"unscheduled":0,"scheduled":0,"excluded":0}');
});

it('compares metadata equality', function () {
    $a = KPIMetadata::make(minutes: 540, scheduled: 1);
    $b = KPIMetadata::make(minutes: 540, scheduled: 1);
    $c = KPIMetadata::make(minutes: 540, scheduled: 2);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

// --- KPIData ---

it('creates KPIData with defaults', function () {
    $data = KPIData::make();

    expect($data->minutes)->toBe(0.0)
        ->and($data->hours)->toBe(0.0)
        ->and($data->period)->toBe(0.0)
        ->and($data->metadata)->toBeInstanceOf(KPIMetadata::class);
});

it('converts KPIData to array', function () {
    $data = KPIData::make(minutes: 60, hours: 1, period: 0.1111);

    expect($data->toArray())->toBe([
        'minutes' => 60.0,
        'hours' => 1.0,
        'period' => 0.1111,
        'metadata' => [
            'minutes' => 0,
            'unscheduled' => 0,
            'scheduled' => 0,
            'excluded' => 0,
        ],
    ]);
});

it('serializes KPIData to json', function () {
    $data = KPIData::make(minutes: 60, hours: 1, period: 0.5);
    $decoded = json_decode(json_encode($data), true);

    expect($decoded['minutes'])->toEqual(60)
        ->and($decoded['hours'])->toEqual(1)
        ->and($decoded['period'])->toEqual(0.5);
});

it('compares KPIData equality', function () {
    $a = KPIData::make(minutes: 60, hours: 1, period: 0.1111);
    $b = KPIData::make(minutes: 60, hours: 1, period: 0.1111);
    $c = KPIData::make(minutes: 120, hours: 2, period: 0.2222);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

it('KPIData equality checks metadata too', function () {
    $a = KPIData::make(minutes: 60, hours: 1, period: 0.5, metadata: KPIMetadata::make(minutes: 540));
    $b = KPIData::make(minutes: 60, hours: 1, period: 0.5, metadata: KPIMetadata::make(minutes: 450));

    expect($a->equals($b))->toBeFalse();
});
