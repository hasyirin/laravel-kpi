<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Data;

readonly class WorkSchedule
{
    public function __construct(public Hour $start, public Hour $end)
    {
        assert($this->start->hour <= $this->end->hour);
    }

    public static function parse(array $data): self
    {
        return new self(Hour::parse($data[0]), Hour::parse($data[1]));
    }

    public static function make(Hour $start, Hour $end): self
    {
        return new self($start, $end);
    }

    public function minutes(): int
    {
        return $this->end->minutes() - $this->start->minutes();
    }
}
