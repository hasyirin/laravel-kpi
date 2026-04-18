<?php

namespace Hasyirin\KPI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Holiday extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function scopeRange(Builder $query, Carbon|string $start, Carbon|string|null $end = null): void
    {
        $query
            ->whereDate('date', '>=', Carbon::parse($start))
            ->whereDate('date', '<=', Carbon::parse($end ?? now()));
    }
}
