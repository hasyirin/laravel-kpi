<?php

namespace Hasyirin\KPI;

use Carbon\CarbonImmutable;
use Hasyirin\KPI\Data\WorkSchedule;
use Illuminate\Support\Carbon;

class KPI
{
    public function calculate(
        Carbon|string $start,
        Carbon|string|null $end = null,
        array $excludeDates = [],
        array $schedules = [],
    ): array {

        if (empty($schedules)) {
            $schedules = config('kpi.schedule');
        }

        $schedules = collect($schedules);

        $total = ['period' => 0, 'minutes' => 0, 'scheduled' => 0, 'unscheduled' => 0, 'excluded' => 0];

        $excludeDates = collect($excludeDates)->map(fn (Carbon|string $date) => Carbon::parse($date));

        $minutes = 0;

        $start = CarbonImmutable::parse($start);
        //        ray("Given start: {$start->format('D - Y - m - d H:i:s')}");

        $end = CarbonImmutable::parse($end ?? now());
        //        ray("Given end: {$end->format('D - Y - m - d H:i:s')}");

        //        [$end, $unscheduled] = $this->sanitizeEndDate($schedules, $end);
        //        $total['unscheduled'] += $unscheduled;

        //        ray("Sanitized end: {$end->format('D - Y - m - d H:i:s')}");

        //        ray([
        //            'start' => $start->format('D - Y-m-d H:i:s'),
        //            'end' => $end->format('D - Y-m-d H:i:s'),
        //            'excluded' => $excludeDates->toArray(),
        //            'schedules' => $schedules->toArray(),
        //        ]);

        $step = $start;

        while ($step < $end) {
            if (empty($schedules[$step->dayOfWeek])) {
                $step = $step->addDay()->startOfDay();
                $total['unscheduled'] += 1;

                //                ray('unscheduled');
                continue;
            }

            if ($excludeDates->contains(fn (Carbon $date) => $date->isSameDay($step))) {
                $step = $step->addDay()->startOfDay();
                $total['excluded'] += 1;

                //                ray('excluded');
                continue;
            }

            /** @var WorkSchedule $schedule */
            $schedule = $schedules[$step->dayOfWeek];
            //            ray("Schedule today is {$schedule->minutes()} minutes");

            $total['minutes'] += $schedule->minutes();
            $total['scheduled'] += 1;

            $step = $this->sanitizeStartDate($schedule, $step);

            ray("Start: {$step->format('D Y-m-d H:i:s')}");

            $finish = min($end, $step->setHour($schedule->end->hour)->setMinute($schedule->end->minute));
            //            ray("Finish: {$finish->format('D Y-m-d H:i:s')}");

            $minutes += $step->diffInMinutes($finish);
            //            ray("Minutes: $minutes");

            $period = bcdiv((string) $step->diffInMinutes($finish), (string) $schedule->minutes(), 4);
            //            ray("Period: {$step->diffInMinutes($finish)} / {$schedule->minutes()} = $period");

            $total['period'] = bcadd($total['period'], $period, 4);

            $step = $finish->addDay()->startOfDay();
        }

        $hours = bcdiv((string) $minutes, '60', 4);

        $data = [
            'minutes' => $minutes,
            'hours' => $hours,
            'period' => $total['period'],
            'total' => [
                'minutes' => $total['minutes'],
                'unscheduled' => $total['unscheduled'],
                'scheduled' => $total['scheduled'],
                'excluded' => $total['excluded'],
            ],
        ];

        ray($data);

        return $data;
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

    //    private function sanitizeEndDate(Collection $schedules, CarbonImmutable $date): array
    //    {
    //        $unscheduled = 0;
    //
    //        while (empty($schedule = $schedules->get($date->dayOfWeek))) {
    //            $date = $date->subDay()->endOfDay();
    //            $unscheduled++;
    //        }
    //
    //        if ($date->hour == $schedule->end->hour && $date->minute >= $schedule->end->minute) {
    //            $date = $date->setMinute($schedule->end->minute)->startOfMinute();
    //        } else if ($date->hour > $schedule->end->hour) {
    //            $date = $date->setHour($schedule->end->hour)->setMinute($schedule->end->minute)->startOfMinute();
    //        }
    //
    //        return [$date, $unscheduled];
    //    }
}
