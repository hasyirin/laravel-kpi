<?php

namespace Hasyirin\KPI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecurringHoliday extends Model
{
    use SoftDeletes;

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

    // scopeEffectiveIn and occurrencesIn added via TDD in Tasks 10 and 11
}
