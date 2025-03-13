<?php

namespace Hasyirin\KPI;

use Carbon\CarbonImmutable;
use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Models\Holiday;
use Illuminate\Support\Carbon;

class KPI
{
    public function calculate(
        Carbon|string $start,
        Carbon|string|null $end = null,
        array $excludeDates = [],
        array $schedules = [],
    ): KPIData {

        if (empty($schedules)) {
            $schedules = config('kpi.schedule');
        }

        $schedules = collect($schedules);

        $total = ['period' => 0, 'minutes' => 0, 'scheduled' => 0, 'unscheduled' => 0, 'excluded' => 0];

        $excludeDates = collect([
            ...collect($excludeDates)->map(fn (Carbon|string $date) => Carbon::parse($date)),
            ...Holiday::query()->range($start, $end)->pluck('date'),
        ]);

        $minutes = 0;

        $start = CarbonImmutable::parse($start);

        $end = CarbonImmutable::parse($end ?? now());

        $step = $start;

        while ($step < $end) {
            if (empty($schedules[$step->dayOfWeek])) {
                $step = $step->addDay()->startOfDay();
                $total['unscheduled'] += 1;

                continue;
            }

            if ($excludeDates->contains(fn (Carbon $date) => $date->isSameDay($step))) {
                $step = $step->addDay()->startOfDay();
                $total['excluded'] += 1;

                continue;
            }

            /** @var WorkSchedule $schedule */
            $schedule = $schedules[$step->dayOfWeek];

            $total['minutes'] += $schedule->minutes();
            $total['scheduled'] += 1;

            $step = $this->sanitizeStartDate($schedule, $step);

            $finish = min($end, $step->setHour($schedule->end->hour)->setMinute($schedule->end->minute));

            $minutes += $step->diffInMinutes($finish);

            $period = bcdiv((string) $step->diffInMinutes($finish), (string) $schedule->minutes(), 4);

            $total['period'] = bcadd($total['period'], $period, 4);

            $step = $finish->addDay()->startOfDay();
        }

        return KPIData::make(
            minutes: $minutes,
            hours: (float) bcdiv((string) $minutes, '60', 4),
            period: $total['period'],
            metadata: KPIMetadata::make(
                minutes: $total['minutes'],
                unscheduled: $total['unscheduled'],
                scheduled: $total['scheduled'],
                excluded: $total['excluded'],
            )
        );
    }

    private function sanitizeStartDate(WorkSchedule $schedule, CarbonImmutable $date): CarbonImmutable
    {
        if ($date->hour == $schedule->start->hour && $date->minute <= $schedule->start->minute) {
            $date = $date->setMinute($schedule->start->minute)->startOfMinute();
        } elseif ($date->hour < $schedule->start->hour) {
            $date = $date->setHour($schedule->start->hour)->setMinute($schedule->start->minute)->startOfMinute();
        }

        return $date;
    }
}
