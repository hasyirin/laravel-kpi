<?php

namespace Hasyirin\KPI\Contracts;

use BackedEnum;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;

/**
 * @property ?Movement $movement
 * @property Collection $movements
 */
interface HasMovement
{
    public function movement(): MorphOne;

    public function movements(): MorphMany;

    public function pass(BackedEnum|string $status, User|int|null $sender = null, User|int|null $actor = null, ?Carbon $receivedAt = null, ?string $notes = null, Collection|array|null $properties = null): Movement;
}
