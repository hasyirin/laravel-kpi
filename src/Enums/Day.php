<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Enums;

enum Day: int
{
    case SUNDAY = 0;
    case MONDAY = 1;
    case TUESDAY = 2;
    case WEDNESDAY = 3;
    case THURSDAY = 4;
    case FRIDAY = 5;
    case SATURDAY = 6;

    public function iso(): int
    {
        return $this->value + 1;
    }
}
