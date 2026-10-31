<?php

namespace Hasyirin\KPI\Data;

use Carbon\CarbonInterval;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final class KPIData implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $seconds,
        public float $period,
        public KPIMetadata $metadata,
    ) {}

    public static function make(int $seconds = 0, float $period = 0, ?KPIMetadata $metadata = null): self
    {
        return new self($seconds, $period, $metadata ?? KPIMetadata::make());
    }

    public function minutes(): float
    {
        return $this->seconds / 60;
    }

    public function hours(): float
    {
        return $this->seconds / 3600;
    }

    public function interval(): CarbonInterval
    {
        return CarbonInterval::seconds($this->seconds)->cascade();
    }

    public function equals(KPIData $other): bool
    {
        return $this->seconds === $other->seconds
            && self::floatEquals($this->period, $other->period)
            && $this->metadata->equals($other->metadata);
    }

    private static function floatEquals(float $a, float $b, float $epsilon = 0.0001): bool
    {
        return abs($a - $b) < $epsilon;
    }

    public function toArray(): array
    {
        return [
            'seconds' => $this->seconds,
            'period' => $this->period,
            'metadata' => $this->metadata->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
