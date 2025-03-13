<?php

namespace Hasyirin\KPI\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final class KPIData implements Arrayable, JsonSerializable
{
    public function __construct(
        public float $minutes,
        public float $hours,
        public float $period,
        public KPIMetadata $metadata,
    ) {}

    public static function make(float $minutes, float $hours, float $period, KPIMetadata $metadata): self
    {
        return new self($minutes, $hours, $period, $metadata);
    }

    public function equals(KPIData $other): bool
    {
        return $this->minutes === $other->minutes
            && $this->hours === $other->hours
            && $this->period === $other->period
            && $this->metadata->equals($other->metadata);
    }

    public function toArray(): array
    {
        return [
            'minutes' => $this->minutes,
            'hours' => $this->hours,
            'period' => $this->period,
            'metadata' => $this->metadata->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
