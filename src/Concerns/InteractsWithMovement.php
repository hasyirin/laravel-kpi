<?php

namespace Hasyirin\KPI\Concerns;

use BackedEnum;
use Carbon\CarbonInterface;
use Hasyirin\KPI\Contracts\HasMovement;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Support\ResourceLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

/**
 * @mixin Model
 * @mixin HasMovement
 */
trait InteractsWithMovement
{
    /** @return MorphOne<Movement, $this> */
    public function movement(): MorphOne
    {
        /** @var class-string<Movement> $model */
        $model = config('kpi.models.movement', Movement::class);

        return $this->morphOne($model, 'movable')
            ->ofMany(['id' => 'max'], fn (Builder $query) => $query->whereNull('completed_at'));
    }

    /** @return MorphMany<Movement, $this> */
    public function movements(): MorphMany
    {
        /** @var class-string<Movement> $model */
        $model = config('kpi.models.movement', Movement::class);

        return $this->morphMany($model, 'movable');
    }

    /**
     * @param  Collection<array-key, mixed>|array<array-key, mixed>|null  $properties
     */
    public function pass(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?CarbonInterface $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement {
        $this->unsetRelation('movement');

        $lock = ResourceLock::forResource($this);

        return $this->movements()->getRelated()->passOnLevel(
            $lock, $this->getMorphClass(), $lock->key, null,
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren, false,
        );
    }

    /**
     * @param  Collection<array-key, mixed>|array<array-key, mixed>|null  $properties
     */
    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?CarbonInterface $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement|false {
        $this->unsetRelation('movement');

        $lock = ResourceLock::forResource($this);

        return $this->movements()->getRelated()->passOnLevel(
            $lock, $this->getMorphClass(), $lock->key, null,
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren, true,
        );
    }
}
