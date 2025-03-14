<?php

namespace Hasyirin\KPI\Concerns;

use BackedEnum;
use Hasyirin\KPI\Contracts\HasMovement;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User;
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
        User|int|null $sender = null,
        User|int|null $receiver = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        bool $completesLastMovement = true,
    ): Movement {
        $sender = ($sender instanceof User) ? $sender->id : $sender;
        $receiver = ($receiver instanceof User) ? $receiver->id : $receiver;

        $receivedAt ??= now();

        DB::beginTransaction();

        if ($completesLastMovement && $this->movement) {
            $this->movement->receiver_id ??= isset($sender) ? null : $receiver;
            $this->movement->completed_at ??= $receivedAt;
            $this->movement->save();
        }

        $movement = new Movement([
            'previous_id' => $this->movement?->id,
            'sender_id' => $sender,
            'receiver_id' => $receiver,
            'received_at' => $receivedAt,
            'status' => $status,
            'notes' => $notes,
            'properties' => $properties ?? [],
        ]);

        $movement->movable()->associate($this);
        $movement->save();

        $this->load('movement');

        DB::commit();

        return $movement;
    }
}
