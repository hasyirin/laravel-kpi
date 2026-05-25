<?php

namespace Hasyirin\KPI;

use Carbon\CarbonImmutable;
use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Data\WorkSchedule;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

class KPI
{
    public function calculate(
        Carbon|string $start,
        Carbon|string|null $end = null,
        Arrayable|array $excludeDates = [],
        Arrayable|array $schedules = [],
    ): KPIData {

        if (empty($schedules)) {
            $schedules = collect(config('kpi.schedule'))->map(fn (array $data) => WorkSchedule::parse($data));
        }

        $schedules = collect($schedules);

        $total = ['period' => 0, 'minutes' => 0, 'scheduled' => 0, 'unscheduled' => 0, 'excluded' => 0];

        $start = CarbonImmutable::parse($start);
        $end = CarbonImmutable::parse($end ?? now());

        // Widen the holiday query window by 7 days to capture late-December substitutes
        // whose observed date may roll into the start of the calc range.
        $queryStart = $start->subDays(7);

        $holiday = config('kpi.models.holiday');
        $recurring = config('kpi.models.recurring_holiday');

        $oneOffOccurrences = $holiday::query()
            ->range($queryStart, $end)
            ->get(['date', 'observes_substitute'])
            ->map(fn ($h) => [
                'date' => $h->date->toImmutable(),
                'observes_substitute' => (bool) $h->observes_substitute,
            ]);

        $recurringOccurrences = $recurring::query()
            ->effectiveIn($queryStart, $end)
            ->get()
            ->flatMap(fn ($r) => $r->occurrencesIn($queryStart, $end)->map(fn ($d) => [
                'date' => $d->toImmutable(),
                'observes_substitute' => (bool) $r->observes_substitute,
            ]));

        $observedDates = $oneOffOccurrences->concat($recurringOccurrences)->pluck('date');

        $excludeDates = collect([
            ...collect($excludeDates)->map(fn (Carbon|string $date) => Carbon::parse($date)),
            ...$observedDates,
        ]);

        $minutes = 0;
        $step = $start;

        while ($step < $end) {
            if (empty($schedules[$step->dayOfWeek])) {
                $step = $step->addDay()->startOfDay();
                $total['unscheduled'] += 1;

                continue;
            }

            if ($excludeDates->contains(fn (Carbon|CarbonImmutable $date) => $date->isSameDay($step))) {
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

            $diff = $finish > $step ? $step->diffInMinutes($finish) : 0.0;

            $minutes += $diff;

            $period = bcdiv((string) max($diff, 0.0001), (string) $schedule->minutes(), 4);

            $total['period'] = bcadd($total['period'], $period, 4);

            $step = $finish->addDay()->startOfDay();
        }

        return KPIData::make(
            minutes: $minutes,
            hours: (float) bcdiv((string) max($minutes, 0.0001), '60', 4),
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
