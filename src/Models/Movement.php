<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Movement extends Model
{
    protected $fillable = [
        'previous_id',
        'movable_id',
        'movable_type',
        'sender_id',
        'sender_type',
        'recipient_id',
        'recipient_type',
        'status',
        'properties',
        'period',
        'hours',
        'received_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'period' => 'float',
            'hours' => 'array',
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

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    public function movable(): MorphTo
    {
        return $this->morphTo();
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}
