<?php

use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Models\RecurringHoliday;

return [
    'formats' => [
        'datetime' => 'd/m/Y H:i A',
    ],

    'tables' => [
        'movements' => 'movements',
        'holidays' => 'holidays',
        'recurring_holidays' => 'recurring_holidays',
    ],

    'models' => [
        'movement' => Movement::class,
        'holiday' => Holiday::class,
        'recurring_holiday' => RecurringHoliday::class,
    ],

    'schedule' => [
        Day::MONDAY->value => ['8:00', '17:00'],
        Day::TUESDAY->value => ['8:00', '17:00'],
        Day::WEDNESDAY->value => ['8:00', '17:00'],
        Day::THURSDAY->value => ['8:00', '17:00'],
        Day::FRIDAY->value => ['8:00', '15:30'],
    ],

    // Day-of-week values whose holidays substitute forward to the next working day
    // when observes_substitute = true on the row. Default empty = no substitution.
    // Malaysian state examples:
    //   Sat-Sun states + post-2025 Johor → [Day::SUNDAY->value]
    //   Kelantan, Terengganu             → [Day::SATURDAY->value]
    //   Kedah                            → [Day::FRIDAY->value]
    'substitute' => [],

    'status' => [

    ],
];
