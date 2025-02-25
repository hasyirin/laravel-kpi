<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Formulas;

use Hasyirin\KPI\Data\Hour;
use Hasyirin\KPI\Data\WorkSchedule;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TurnaroundTime
{

    public int $totalMinutes;

    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public Collection $schedules,
        public Collection $excludeDates,
    ) {
        assert($this->schedules->every(fn ($item) => $item instanceof WorkSchedule));

        $this->excludeDates = $this->excludeDates->map(fn ($date) => Carbon::parse($date));

        $start = intdiv($this->schedules->sum(fn (WorkSchedule $schedule) => $schedule->start->minutes()), $this->schedules->count());
        $end = intdiv($this->schedules->sum(fn (WorkSchedule $schedule) => $schedule->end->minutes()), $this->schedules->count());

        $this->totalMinutes = abs($start - $end);
    }

    public function calculate(): array
    {
        $from = $this->from->toImmutable();
        $to = $this->to->toImmutable();

        return [
            'hours' => 0,
            'minutes' => 0,
            'seconds' => 0,
            'period' => 0,
            'excluded' => 0,
        ];
    }

    public static function make(Carbon|string $from, Carbon|string|null $to = null, Collection|Arrayable|array|null $schedules = null, Collection|Arrayable|array $excludeDates = []): self
    {
        return new self(
            from: Carbon::parse($from),
            to: Carbon::parse($to),
            schedules: collect($schedules ?? config('kpi.schedule')),
            excludeDates: collect($excludeDates),
        );
    }
}
