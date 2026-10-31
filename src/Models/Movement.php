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
 * @property int $previous_id
 * @property int $movable_id
 * @property string $movable_type
 * @property int $sender_id
 * @property string $sender_type
 * @property ?int $actor_id
 * @property ?string $actor_type
 * @property string $status
 * @property ?float $period
 * @property ?int $seconds
 * @property string $notes
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

    /**
     * @internal
     */
    public function closeLocked(CarbonInterface $at, ?Model $backfillActor = null): bool
    {
        $row = $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->toBase()->first();

        if ($row === null) {
            throw new LogicException("Cannot close missing movement {$this->getKey()}.");
        }

        $this->setRawAttributes((array) $row, true);

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
        $receivedAt ??= now();

        return $this->getConnection()->transaction(function () use (
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren
        ) {
            if ($this->lockAndCheckCompleted()) {
                throw new LogicException('Cannot pass from a completed movement.');
            }

            $previous = $this->children()
                ->whereNull('completed_at')
                ->lockForUpdate()
                ->latest('received_at')
                ->latest('id')
                ->first();

            $superseded = null;

            if ($previous && $this->shouldSupersede($previous, $supersede)) {
                $previous->closeLocked($receivedAt, $sender ? null : $actor);
                $superseded = $previous;
            }

            $movement = $this->children()->make([
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

            // Inherit morph keys from the receiver. Direct assignment avoids loading the
            // resource model just to call ->movable()->associate() and discard it.
            $movement->movable_id = $this->movable_id;
            $movement->movable_type = $this->movable_type;
            $movement->save();

            event(new Passed($movement, $superseded));

            return $movement;
        });
    }

    protected function shouldSupersede(self $previous, ?bool $supersede): bool
    {
        if ($supersede === false) {
            return false;
        }
        if ($supersede === true) {
            return true;
        }

        // null = inferred: close iff (no open children) AND (expects_children == false)
        if ($previous->expects_children) {
            return false;
        }

        return $previous->children()->whereNull('completed_at')->doesntExist();
    }

    protected function lockAndCheckCompleted(): bool
    {
        return filled(
            $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->value('completed_at')
        );
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
        $latest = $this->children()
            ->whereNull('completed_at')
            ->latest('received_at')
            ->latest('id')
            ->first();

        $statusValue = $status instanceof BackedEnum ? $status->value : $status;
        $sameStatus = $latest?->status === (string) $statusValue;
        $sameActor = $latest?->actor_type === $actor?->getMorphClass()
            && (string) $latest?->actor_id === (string) $actor?->getKey();

        if ($sameStatus && $sameActor) {
            return false;
        }

        return $this->pass($status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren);
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
