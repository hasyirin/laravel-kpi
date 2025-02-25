<?php

use Hasyirin\KPI\Data\Hour;
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Enums\Day;

return [
    'schedule' => [
        WorkSchedule::make(Day::MONDAY, start: Hour::make(8), end: Hour::make(17)),
        WorkSchedule::make(Day::TUESDAY, start: Hour::make(8), end: Hour::make(17)),
        WorkSchedule::make(Day::WEDNESDAY, start: Hour::make(8), end: Hour::make(17)),
        WorkSchedule::make(Day::THURSDAY, start: Hour::make(8), end: Hour::make(17)),
        WorkSchedule::make(Day::FRIDAY, start: Hour::make(8), end: Hour::make(15, 30)),
    ],
];
