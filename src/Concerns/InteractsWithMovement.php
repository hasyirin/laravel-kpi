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
        bool $completesLastMovement = true,
    ): Movement {
        $receivedAt ??= now();

        DB::beginTransaction();

        if ($completesLastMovement && $this->movement) {
            $this->movement->actor_id ??= isset($sender) ? null : $actor?->id;
            $this->movement->actor_type ??= isset($sender) ? null : $actor?->getMorphClass();
            $this->movement->completed_at ??= $receivedAt;
            $this->movement->save();
        }

        $movement = new (config('kpi.models.movement'))([
            'previous_id' => $this->movement?->id,
            'sender_id' => $sender?->id,
            'sender_type' => $sender?->getMorphClass(),
            'actor_id' => $actor?->id,
            'actor_type' => $actor?->getMorphClass(),
            'received_at' => $receivedAt,
            'status' => $status,
            'notes' => $notes,
            'properties' => $properties ?? [],
        ]);

        $movement->movable()->associate($this);
        $movement->save();

        event(new Passed($movement, $this->movement));

        $this->load('movement');

        DB::commit();

        return $movement;
    }

    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        bool $completesLastMovement = true
    ): Movement|false {
        if ($this->movement?->status === ($status instanceof BackedEnum ? $status->value : $status)) {
            return false;
        }

        return $this->pass($status, $sender, $actor, $receivedAt, $notes, $properties, $completesLastMovement);
    }
}
