<?php

namespace Hasyirin\KPI;

use Illuminate\Support\Carbon;

class KPI
{
    public function calculate(
        Carbon|string $start,
        Carbon|string|null $end,
        array $excludeDates = [],
        ?array $days = null
    ): array {

        $days ??= config('kpi.days');

        return [

        ];
    }
}
