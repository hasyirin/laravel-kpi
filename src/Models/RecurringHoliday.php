<?php

namespace Hasyirin\KPI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecurringHoliday extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'month',
        'day',
        'observes_substitute',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'day' => 'integer',
            'observes_substitute' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function getTable(): string
    {
        return config('kpi.tables.recurring_holidays', parent::getTable());
    }

    public function scopeEffectiveIn(Builder $query, Carbon|string $start, Carbon|string|null $end = null): void
    {
        $start = Carbon::parse($start);
        $end = Carbon::parse($end ?? now());

        $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $end))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $start));
    }

    // occurrencesIn added via TDD in Task 11
}
