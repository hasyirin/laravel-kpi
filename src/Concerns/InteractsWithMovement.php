<?php

namespace Hasyirin\KPI\Concerns;

use BackedEnum;
use Hasyirin\KPI\Contracts\HasMovement;
use Hasyirin\KPI\Events\Passed;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @mixin Model
 * @mixin HasMovement
 */
trait InteractsWithMovement
{
    public function movement(): MorphOne
    {
        return $this->morphOne(config('kpi.models.movement'), 'movable')
            ->ofMany(['received_at' => 'max'], fn ($query) => $query->whereNull('completed_at'));
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(config('kpi.models.movement'), 'movable');
    }

    public function pass(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement {
        $receivedAt ??= now();

        return DB::transaction(function () use (
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren
        ) {
            $previous = $this->movements()
                ->whereNull('parent_id')
                ->whereNull('completed_at')
                ->lockForUpdate()
                ->latest('received_at')
                ->first();

            $superseded = null;

            if ($previous && $this->shouldSupersedeRoot($previous, $supersede)) {
                $previous->actor_id ??= isset($sender) ? null : $actor?->getKey();
                $previous->actor_type ??= isset($sender) ? null : $actor?->getMorphClass();

                // complete() saves with both the actor changes and completed_at in one query
                $previous->complete($receivedAt);
                $superseded = $previous;
            }

            $movement = new (config('kpi.models.movement'))([
                'previous_id' => $previous?->getKey(),
                'sender_id' => $sender?->getKey(),
                'sender_type' => $sender?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'received_at' => $receivedAt,
                'status' => $status instanceof BackedEnum ? $status->value : $status,
                'notes' => $notes,
                'properties' => $properties ?? [],
                'expects_children' => $expectsChildren,
            ]);

            $movement->movable()->associate($this);
            $movement->save();

            event(new Passed($movement, $superseded));

            $this->load('movement');

            return $movement;
        });
    }

    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement|false {
        $latestRoot = $this->movements()
            ->whereNull('parent_id')
            ->whereNull('completed_at')
            ->latest('received_at')
            ->first();

        $statusValue = $status instanceof BackedEnum ? $status->value : $status;
        $sameStatus = $latestRoot?->status === $statusValue;
        $sameActor = $latestRoot?->actor_type === $actor?->getMorphClass()
            && $latestRoot?->actor_id === $actor?->getKey();

        if ($sameStatus && $sameActor) {
            return false;
        }

        return $this->pass($status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren);
    }

    protected function shouldSupersedeRoot(Movement $previous, ?bool $supersede): bool
    {
        if ($supersede === false) {
            return false;
        }
        if ($supersede === true) {
            return true;
        }

        if ($previous->expects_children) {
            return false;
        }

        return $previous->children()->whereNull('completed_at')->doesntExist();
    }
}
