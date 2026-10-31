<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Data;

use InvalidArgumentException;

readonly class Hour
{
    public function __construct(public int $hour, public int $minute = 0)
    {
        if ($this->hour < 0 || $this->hour > 23) {
            throw new InvalidArgumentException('An hour must be between 0 and 23.');
        }

        if ($this->minute < 0 || $this->minute > 59) {
            throw new InvalidArgumentException('A minute must be between 0 and 59.');
        }
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
