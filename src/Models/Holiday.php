<?php

namespace Hasyirin\KPI\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property CarbonInterface $date
 * @property bool $observes_substitute
 */
class Holiday extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'date',
        'observes_substitute',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'observes_substitute' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? config()->string('kpi.tables.holidays', parent::getTable());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeRange(Builder $query, CarbonInterface|string $start, CarbonInterface|string|null $end = null): void
    {
        $query
            ->where('date', '>=', Carbon::parse($start)->toDateString())
            ->where('date', '<', Carbon::parse($end ?? now())->addDay()->toDateString());
    }
}
