<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'receiver_id',
        'receiver_type',
        'status',
        'period',
        'hours',
        'notes',
        'properties',
        'received_at',
        'completed_at',
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

    protected function recalculate(): void {}

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

    public function receiver(): MorphTo
    {
        return $this->morphTo();
    }
}
