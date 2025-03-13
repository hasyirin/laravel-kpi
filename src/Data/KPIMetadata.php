<?php

namespace Hasyirin\KPI\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final class KPIMetadata implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $minutes,
        public int $unscheduled = 0,
        public int $scheduled = 0,
        public int $excluded = 0,
    ) {}

    public static function make(int $minutes, int $unscheduled = 0, int $scheduled = 0, int $excluded = 0): self
    {
        return new self($minutes, $unscheduled, $scheduled, $excluded);
    }

    public function equals(KPIMetadata $other): bool
    {
        return $this->minutes === $other->minutes
            && $this->unscheduled === $other->unscheduled
            && $this->scheduled === $other->scheduled
            && $this->excluded === $other->excluded;
    }

    public function toArray(): array
    {
        return [
            'minutes' => $this->minutes,
            'unscheduled' => $this->unscheduled,
            'scheduled' => $this->scheduled,
            'excluded' => $this->excluded,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
