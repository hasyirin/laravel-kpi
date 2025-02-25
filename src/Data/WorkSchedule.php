<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Data;

use Hasyirin\KPI\Enums\Day;

readonly class WorkSchedule
{
    public function __construct(public Day $day, public Hour $start, public Hour $end) {}

    public static function make(Day $day, Hour $start, Hour $end): self
    {
        return new self($day, $start, $end);
    }
}
