<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Models;

use Carbon\CarbonInterface;
use Hasyirin\KPI\Facades\KPI;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $parent_id
 * @property int $previous_id
 * @property int $movable_id
 * @property string $movable_type
 * @property int $sender_id
 * @property string $sender_type
 * @property int $actor_id
 * @property string $actor_type
 * @property string $status
 * @property float $period
 * @property float $hours
 * @property string $notes
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
            'hours' => 'array',
            'properties' => 'array',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::saving(fn (self $movement) => $movement->recalculate());
    }

    protected function recalculate(): void
    {
        $kpi = KPI::calculate($this->received_at, $this->completed_at);

        $this->period = filled($this->completed_at) ? $kpi->period : null;
        $this->hours = filled($this->completed_at) ? $kpi->hours : null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class);
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
        return Attribute::make(get: fn () => $this->period ?? KPI::calculate($this->received_at, $this->completed_at)->period)->withoutObjectCaching();
    }

    public function interval(): Attribute
    {
        return Attribute::make(get: fn () => ($this->period > 0 || $this->period === null)
            ? ($this->hours ?? KPI::calculate($this->received_at, $this->completed_at)->hours) * 60 * 60
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
        return Attribute::make(get: fn () => $this->received_at->format(config('kpi.formats.datetime')));
    }
}
