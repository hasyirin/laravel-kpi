<?php

use Hasyirin\KPI\Data\Hour;
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Models\Holiday;

return [
    'tables' => [
        'movements' => 'movements',
        'holidays' => 'holidays',
    ],

    'models' => [
        'movement' => Movement::class,
        'holiday' => Holiday::class,
    ],

    'schedule' => [
        Day::MONDAY->value => WorkSchedule::make(start: Hour::make(8), end: Hour::make(17)),
        Day::TUESDAY->value => WorkSchedule::make(start: Hour::make(8), end: Hour::make(17)),
        Day::WEDNESDAY->value => WorkSchedule::make(start: Hour::make(8), end: Hour::make(17)),
        Day::THURSDAY->value => WorkSchedule::make(start: Hour::make(8), end: Hour::make(17)),
        Day::FRIDAY->value => WorkSchedule::make(start: Hour::make(8), end: Hour::make(15, 30)),
    ],
];
