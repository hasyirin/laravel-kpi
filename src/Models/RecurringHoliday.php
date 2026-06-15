<?php

namespace Hasyirin\KPI\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property int $month
 * @property int $day
 * @property bool $observes_substitute
 * @property ?CarbonInterface $effective_from
 * @property ?CarbonInterface $effective_until
 */
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

    public function scopeEffectiveIn(Builder $query, CarbonInterface|string $start, CarbonInterface|string|null $end = null): void
    {
        $start = Carbon::parse($start);
        $end = Carbon::parse($end ?? now());

        $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $end))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $start));
    }

    public function occurrencesIn(CarbonInterface|string $start, CarbonInterface|string $end): Collection
    {
        $start = Carbon::parse($start);
        $end = Carbon::parse($end);

        return collect(range($start->year, $end->year))
            ->filter(fn (int $year) => checkdate($this->month, $this->day, $year))
            ->map(fn (int $year) => Carbon::create($year, $this->month, $this->day))
            ->filter(fn (CarbonInterface $date) => (! $this->effective_from || $date->gte($this->effective_from)) &&
                (! $this->effective_until || $date->lte($this->effective_until)) &&
                $date->between($start, $end)
            )
            ->values();
    }
}
