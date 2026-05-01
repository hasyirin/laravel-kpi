<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Hasyirin\KPI\Data\KPIData;
use Hasyirin\KPI\Events\Completed;
use Hasyirin\KPI\Events\Passed;
use Hasyirin\KPI\Facades\KPI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property ?int $parent_id
 * @property int $previous_id
 * @property int $movable_id
 * @property string $movable_type
 * @property int $sender_id
 * @property string $sender_type
 * @property int $actor_id
 * @property string $actor_type
 * @property string $status
 * @property ?float $period
 * @property ?float $hours
 * @property string $notes
 * @property bool $expects_children
 * @property Carbon $received_at
 * @property ?Carbon $completed_at
 * @property float $interval
 */
class Movement extends Model
{
    use SoftDeletes;

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
        'hours',
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
            'hours' => 'float',
            'properties' => 'array',
            'expects_children' => 'boolean',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $movement) {
            if (! filled($movement->completed_at)) {
                $movement->period = null;
                $movement->hours = null;

                return;
            }

            $kpi = $movement->calculate();
            $movement->period = $kpi->period;
            $movement->hours = $kpi->hours;
        });
    }

    protected function calculate(): KPIData
    {
        return ! in_array($this->status, config("kpi.status.$this->movable_type.except", []))
            ? KPI::calculate($this->received_at, $this->completed_at)
            : KPIData::make();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function complete(?Carbon $at = null): self
    {
        if (filled($this->completed_at)) {
            return $this;
        }

        $at ??= now();

        return DB::transaction(function () use ($at) {
            $this->children()
                ->whereNull('completed_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (self $child) => $child->complete($at));

            $this->completed_at = $at;
            $this->save();

            event(new Completed($this));

            return $this;
        });
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
    ): self {
        $receivedAt ??= now();

        return DB::transaction(function () use (
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren
        ) {
            $previous = $this->children()
                ->whereNull('completed_at')
                ->lockForUpdate()
                ->latest('received_at')
                ->first();

            $superseded = null;

            if ($previous && $this->shouldSupersede($previous, $supersede)) {
                $previous->actor_id ??= isset($sender) ? null : $actor?->getKey();
                $previous->actor_type ??= isset($sender) ? null : $actor?->getMorphClass();

                // complete() saves with both the actor changes and completed_at in one query
                $previous->complete($receivedAt);
                $superseded = $previous;
            }

            $movement = new (config('kpi.models.movement'))([
                'parent_id' => $this->getKey(),
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

    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): self|false {
        $latest = $this->children()
            ->whereNull('completed_at')
            ->latest('received_at')
            ->first();

        $statusValue = $status instanceof BackedEnum ? $status->value : $status;
        $sameStatus = $latest?->status === $statusValue;
        $sameActor = $latest?->actor_type === $actor?->getMorphClass()
            && $latest?->actor_id === $actor?->getKey();

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

    public function formattedPeriod(): Attribute
    {
        return Attribute::make(get: fn () => $this->period ?? $this->calculate()->period)->withoutObjectCaching();
    }

    public function interval(): Attribute
    {
        return Attribute::make(get: fn () => ($this->period > 0 || $this->period === null)
            ? ($this->hours ?? $this->calculate()->hours) * 60 * 60
            : 0
        )->withoutObjectCaching();
    }

    public function formattedInterval(): Attribute
    {
        return Attribute::make(
            get: fn () => now()->addSeconds($this->interval)->diffForHumans([
                'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 2,
            ]),
        )->withoutObjectCaching();
    }

    public function formattedReceivedAt(): Attribute
    {
        return Attribute::make(get: fn () => $this->received_at?->format(config('kpi.formats.datetime')));
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
