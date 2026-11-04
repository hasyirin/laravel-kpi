<?php

namespace Hasyirin\KPI\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\RecurringHoliday;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class HolidayCalendar
{
    /**
     * @param  Collection<int, WorkSchedule>  $schedules
     * @param  Arrayable<array-key, CarbonInterface|string>|array<array-key, CarbonInterface|string>  $excludeDates
     * @return array<string, true>
     */
    public function excludedDays(CarbonImmutable $start, CarbonImmutable $end, Collection $schedules, Arrayable|array $excludeDates = []): array
    {
        $queryStart = $start->subDays(7)->toDateString();
        $queryEnd = $end->toDateString();

        /** @var class-string<Holiday> $holiday */
        $holiday = config('kpi.models.holiday', Holiday::class);
        /** @var class-string<RecurringHoliday> $recurring */
        $recurring = config('kpi.models.recurring_holiday', RecurringHoliday::class);

        $oneOffOccurrences = $holiday::query()
            ->range($queryStart, $queryEnd)
            ->get(['date', 'observes_substitute'])
            ->map(fn ($h) => [
                'date' => $h->date->toImmutable(),
                'observes_substitute' => (bool) $h->observes_substitute,
            ]);

        $recurringOccurrences = $recurring::query()
            ->effectiveIn($queryStart, $queryEnd)
            ->get()
            ->flatMap(fn ($r) => $r->occurrencesIn($queryStart, $queryEnd)->map(fn ($d) => [
                'date' => $d->toImmutable(),
                'observes_substitute' => (bool) $r->observes_substitute,
            ]));

        /** @var list<int> $substitute */
        $substitute = config('kpi.substitute', []);
        $substituteDays = collect($substitute)->flip();

        $days = [];

        foreach (new Collection($excludeDates) as $date) {
            $days[Carbon::parse($date)->format('Y-m-d')] = true;
        }

        foreach ($oneOffOccurrences->concat($recurringOccurrences) as $occurrence) {
            $date = $occurrence['date'];

            if ($occurrence['observes_substitute']
                && $substituteDays->has($date->dayOfWeek)
                && empty($schedules[$date->dayOfWeek])
            ) {
                $date = $this->nextScheduledDay($date, $schedules);
            }

            $days[$date->format('Y-m-d')] = true;
        }

        return $days;
    }

    /**
     * @param  Collection<int, WorkSchedule>  $schedules
     */
    private function nextScheduledDay(CarbonInterface $holiday, Collection $schedules): CarbonInterface
    {
        $date = $holiday;

        for ($i = 0; $i < 7; $i++) {
            $date = $date->addDay();

            if (! empty($schedules[$date->dayOfWeek])) {
                return $date;
            }
        }

        return $holiday;
    }
}
