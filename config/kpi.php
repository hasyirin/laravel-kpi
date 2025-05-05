<?php

use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;

return [
    'formats' => [
        'datetime' => 'd/m/Y H:i A',
    ],

    'tables' => [
        'movements' => 'movements',
        'holidays' => 'holidays',
    ],

    'models' => [
        'movement' => Movement::class,
        'holiday' => Holiday::class,
    ],

    'schedule' => [
        Day::MONDAY->value => ['8:00', '17:00'],
        Day::TUESDAY->value => ['8:00', '17:00'],
        Day::WEDNESDAY->value => ['8:00', '17:00'],
        Day::THURSDAY->value => ['8:00', '17:00'],
        Day::FRIDAY->value => ['8:00', '15:30'],
    ],
];
