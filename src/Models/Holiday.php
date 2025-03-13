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

    public function scopeRange(Builder $query, Carbon $start, ?Carbon $end = null): Builder
    {
        return $query->whereDate('date', '>=', $start)->whereDate('date', '<=', $end ?? now());
    }
}
