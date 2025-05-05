<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Data;

readonly class Hour
{
    public function __construct(public int $hour, public int $minute = 0)
    {
        assert($this->hour >= 0 && $this->hour < 24);
        assert($this->minute >= 0 && $this->minute < 60);
    }

    public function minutes(): int
    {
        return ($this->hour * 60) + $this->minute;
    }

    public static function parse(string $data): self
    {
        [$hour, $minute] = explode(':', $data);
        return new self(intval($hour), intval($minute));
    }

    public static function make(int $hour, int $minute = 0): self
    {
        return new self($hour, $minute);
    }

    public static function fromMinutes(int $minutes): self
    {
        return new self(intdiv($minutes, 60), $minutes % 60);
    }
}
