<?php

namespace Hasyirin\KPI\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final class KPIMetadata implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $scheduledSeconds = 0,
        public int $scheduledDays = 0,
        public int $unscheduledDays = 0,
        public int $excludedDays = 0,
    ) {}

    public static function make(int $scheduledSeconds = 0, int $scheduledDays = 0, int $unscheduledDays = 0, int $excludedDays = 0): self
    {
        return new self($scheduledSeconds, $scheduledDays, $unscheduledDays, $excludedDays);
    }

    public function equals(KPIMetadata $other): bool
    {
        return $this->scheduledSeconds === $other->scheduledSeconds
            && $this->scheduledDays === $other->scheduledDays
            && $this->unscheduledDays === $other->unscheduledDays
            && $this->excludedDays === $other->excludedDays;
    }

    public function toArray(): array
    {
        return [
            'scheduledSeconds' => $this->scheduledSeconds,
            'scheduledDays' => $this->scheduledDays,
            'unscheduledDays' => $this->unscheduledDays,
            'excludedDays' => $this->excludedDays,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
