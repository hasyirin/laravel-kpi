<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Events\Completed;
use Hasyirin\KPI\Events\Passed;
use Hasyirin\KPI\Facades\KPI;
use Hasyirin\KPI\Support\ResourceLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use LogicException;

/**
 * @property int $id
 * @property ?int $parent_id
 * @property ?int $previous_id
 * @property int $movable_id
 * @property string $movable_type
 * @property int $position
 * @property ?int $sender_id
 * @property ?string $sender_type
 * @property ?int $actor_id
 * @property ?string $actor_type
 * @property string $status
 * @property ?float $period
 * @property ?int $seconds
 * @property ?string $notes
 * @property bool $expects_children
 * @property CarbonInterface $received_at
 * @property ?CarbonInterface $completed_at
 */
class Movement extends Model
{
    protected $fillable = [
        'parent_id',
        'previous_id',
        'movable_id',
        'movable_type',
        'sender_id',
        'sender_type',
        'actor_id',
        'actor_type',
        'status',
        'period',
        'seconds',
        'notes',
        'properties',
        'expects_children',
        'received_at',
        'completed_at',
    ];

    protected $attributes = [
        'properties' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'period' => 'float',
            'seconds' => 'integer',
            'properties' => 'array',
            'expects_children' => 'boolean',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? config('kpi.tables.movements', parent::getTable());
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $movement) {
            if ($movement->isDirty(['received_at', 'completed_at', 'status'])) {
                $movement->fillKPI();
            }
        });
    }

    public function recalculate(): self
    {
        $this->fillKPI();
        $this->save();

        return $this;
    }

    public function kpi(?CarbonInterface $at = null): KPIData
    {
        if ($this->isExcepted()) {
            return KPIData::make();
        }

        if (filled($this->completed_at)) {
            return KPIData::make($this->seconds ?? 0, $this->period ?? 0);
        }

        return KPI::calculate($this->received_at, $at ?? now());
    }

    public function isExcepted(): bool
    {
        $excepted = collect(config("kpi.status.$this->movable_type.except", []))
            ->map(fn (BackedEnum|string $status) => (string) ($status instanceof BackedEnum ? $status->value : $status));

        return $excepted->contains((string) $this->status);
    }

    protected function fillKPI(): void
    {
        if (blank($this->completed_at)) {
            $this->period = null;
            $this->seconds = null;

            return;
        }

        $kpi = $this->isExcepted()
            ? KPIData::make()
            : KPI::calculate($this->received_at, $this->completed_at);

        $this->period = $kpi->period;
        $this->seconds = $kpi->seconds;
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function previous(): BelongsTo
    {
        /** @var class-string<self> $model */
        $model = config('kpi.models.movement', self::class);

        return $this->belongsTo($model);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        /** @var class-string<self> $model */
        $model = config('kpi.models.movement', self::class);

        return $this->belongsTo($model, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        /** @var class-string<self> $model */
        $model = config('kpi.models.movement', self::class);

        return $this->hasMany($model, 'parent_id');
    }

    public function complete(?CarbonInterface $at = null): self
    {
        ResourceLock::forMovement($this)->run($this->getConnection(), function () use ($at) {
            $this->closeLocked(($at ?? now())->copy()->setTimezone(config('app.timezone')));
        });

        return $this;
    }

    protected function closeLocked(CarbonInterface $at, ?Model $backfillActor = null): bool
    {
        $this->fillFromLockedRow();

        if (filled($this->completed_at)) {
            return false;
        }

        $this->children()
            ->whereNull('completed_at')
            ->get()
            ->each(fn (self $child) => $child->closeLocked($at));

        if ($backfillActor !== null && $this->actor_id === null) {
            $this->actor_id = $backfillActor->getKey();
            $this->actor_type = $backfillActor->getMorphClass();
        }

        $this->completed_at = $at->max($this->received_at);
        $this->save();

        event(new Completed($this));

        return true;
    }

    protected function fillFromLockedRow(): void
    {
        $row = $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->toBase()->first();

        if ($row === null) {
            throw new LogicException("Cannot lock missing movement {$this->getKey()}.");
        }

        $this->setRawAttributes((array) $row, true);
    }

    /**
     * @internal
     *
     * @return ($onlyIfNotCurrent is true ? self|false : self)
     */
    public function passOnLevel(
        ResourceLock $lock,
        string $movableType,
        int|string $movableId,
        ?self $parent,
        BackedEnum|string $status,
        ?Model $sender,
        ?Model $actor,
        ?CarbonInterface $receivedAt,
        ?string $notes,
        Collection|array|null $properties,
        ?bool $supersede,
        bool $expectsChildren,
        bool $onlyIfNotCurrent,
    ): self|false {
        $statusValue = (string) ($status instanceof BackedEnum ? $status->value : $status);

        return $lock->run($this->getConnection(), function () use (
            $movableType, $movableId, $parent, $statusValue, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren, $onlyIfNotCurrent
        ) {
            if ($parent !== null) {
                $parent->fillFromLockedRow();

                if (filled($parent->completed_at)) {
                    throw new LogicException('Cannot pass from a completed movement.');
                }
            }

            $at = ($receivedAt ?? now())->copy()->setTimezone(config('app.timezone'));

            $level = $this->newQueryWithoutScopes()
                ->where('movable_type', $movableType)
                ->where('movable_id', $movableId)
                ->where('level_key', $parent?->getKey() ?? 0)
                ->orderByDesc('position');

            $link = $level->clone()->first();
            $target = $level->clone()->whereNull('completed_at')->first();

            if ($onlyIfNotCurrent && $target?->isCurrent($statusValue, $actor)) {
                return false;
            }

            $superseded = null;

            if ($target !== null && $this->shouldSupersede($target, $supersede)) {
                $superseded = $target->closeLocked($at, $sender ? null : $actor) ? $target : null;
            }

            $movement = $this->newInstance();
            $movement->forceFill([
                'parent_id' => $parent?->getKey(),
                'previous_id' => $link?->getKey(),
                'position' => ($link->position ?? 0) + 1,
                'movable_type' => $movableType,
                'movable_id' => $movableId,
                'sender_id' => $sender?->getKey(),
                'sender_type' => $sender?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'received_at' => $at,
                'status' => $statusValue,
                'notes' => $notes,
                'properties' => $properties ?? [],
                'expects_children' => $expectsChildren,
            ]);
            $movement->save();

            event(new Passed($movement, $superseded));

            return $movement;
        });
    }

    protected function isCurrent(string $status, ?Model $actor): bool
    {
        return (string) $this->status === $status
            && $this->actor_type === $actor?->getMorphClass()
            && (string) $this->actor_id === (string) $actor?->getKey();
    }

    public function pass(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?CarbonInterface $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): self {
        return $this->passOnLevel(
            ResourceLock::forMovement($this), $this->movable_type, $this->movable_id, $this,
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren, false,
        );
    }

    protected function shouldSupersede(self $target, ?bool $supersede): bool
    {
        if ($supersede === false) {
            return false;
        }
        if ($supersede === true) {
            return true;
        }

        // null = inferred: close iff (no open children) AND (expects_children == false)
        if ($target->expects_children) {
            return false;
        }

        return $target->children()->whereNull('completed_at')->doesntExist();
    }

    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?CarbonInterface $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): self|false {
        return $this->passOnLevel(
            ResourceLock::forMovement($this), $this->movable_type, $this->movable_id, $this,
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren, true,
        );
    }

    public function movable(): MorphTo
    {
        return $this->morphTo();
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    public function scopeClosed(Builder $query): void
    {
        $query->whereNotNull('completed_at');
    }

    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }
}
