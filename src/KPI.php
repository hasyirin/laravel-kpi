<?php

namespace Hasyirin\KPI;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Data\KPIMetadata;
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Support\HolidayCalendar;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

class KPI
{
    /**
     * @param  Arrayable<array-key, CarbonInterface|string>|array<array-key, CarbonInterface|string>  $excludeDates
     * @param  Arrayable<int, WorkSchedule>|array<int, WorkSchedule>  $schedules
     */
    public function calculate(
        CarbonInterface|string $start,
        CarbonInterface|string|null $end = null,
        Arrayable|array $excludeDates = [],
        Arrayable|array $schedules = [],
    ): KPIData {
        $schedules = new Collection($schedules);

        if ($schedules->isEmpty()) {
            /** @var array<int, array{string, string}> $configuredSchedules */
            $configuredSchedules = config('kpi.schedule', []);
            $schedules = collect($configuredSchedules)->map(fn (array $data) => WorkSchedule::parse($data));
        }

        $configuredTimezone = config('kpi.timezone');
        $timezone = is_string($configuredTimezone) ? $configuredTimezone : config()->string('app.timezone');

        $start = $this->toTimezone($start, $timezone);
        $end = $this->toTimezone($end ?? now(), $timezone);

        if ($end->getTimestamp() <= $start->getTimestamp()) {
            return KPIData::make();
        }

        $excludedDays = app(HolidayCalendar::class)->excludedDays($start, $end, $schedules, $excludeDates);

        $seconds = 0;
        $periodSum = 0.0;
        $scheduledSeconds = 0;
        $scheduledDays = 0;
        $unscheduledDays = 0;
        $excludedCount = 0;

        for ($day = $start->startOfDay(); $day < $end; $day = $day->addDay()->startOfDay()) {
            /** @var ?WorkSchedule $schedule */
            $schedule = $schedules[$day->dayOfWeek] ?? null;

            if (empty($schedule)) {
                $unscheduledDays++;

                continue;
            }

            if (isset($excludedDays[$day->format('Y-m-d')])) {
                $excludedCount++;

                continue;
            }

            $windowStart = $day->setTime($schedule->start->hour, $schedule->start->minute)->getTimestamp();
            $windowEnd = $day->setTime($schedule->end->hour, $schedule->end->minute)->getTimestamp();
            $length = $windowEnd - $windowStart;

            $scheduledDays++;
            $scheduledSeconds += $length;

            if ($length <= 0) {
                continue;
            }

            $overlap = max(0, min($end->getTimestamp(), $windowEnd) - max($start->getTimestamp(), $windowStart));

            $seconds += $overlap;
            $periodSum += $overlap / $length;
        }

        return KPIData::make(
            seconds: $seconds,
            period: round($periodSum, 4),
            metadata: KPIMetadata::make(
                scheduledSeconds: $scheduledSeconds,
                scheduledDays: $scheduledDays,
                unscheduledDays: $unscheduledDays,
                excludedDays: $excludedCount,
            ),
        );
    }

    private function toTimezone(CarbonInterface|string $moment, string $timezone): CarbonImmutable
    {
        return $moment instanceof CarbonInterface
            ? CarbonImmutable::instance($moment)->setTimezone($timezone)
            : CarbonImmutable::parse($moment, $timezone)->setTimezone($timezone);
    }
}
