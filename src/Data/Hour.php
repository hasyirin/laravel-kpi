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
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $data, $matches) !== 1) {
            throw new InvalidArgumentException('An hour must be written as H:MM or HH:MM.');
        }

        return new self((int) $matches[1], (int) $matches[2]);
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
