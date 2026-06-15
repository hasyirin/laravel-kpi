<?php

namespace Hasyirin\KPI\Contracts;

use BackedEnum;
use Carbon\CarbonInterface;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

/**
 * @property ?Movement $movement
 * @property Collection $movements
 */
interface HasMovement
{
    public function movement(): MorphOne;

    public function movements(): MorphMany;

    public function pass(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?CarbonInterface $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement;

    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?CarbonInterface $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement|false;
}
