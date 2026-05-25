# v3.1 — Fixed/recurring public holidays with substitute-day support

**Status:** Spec, not yet implemented.
**Target version:** 3.1.0 (minor bump, fully additive — no breaking changes).

## Problem

`laravel-kpi`'s `Holiday` model only stores one-off dated rows. For real-world public holidays like Labour Day (1 May) or Malaysian National Day (31 August) that recur on the same gregorian month/day every year, consumers must seed one row per year per holiday — repetitive and error-prone.

Additionally, Malaysian government practice substitutes certain public holidays to a working day when they fall on a rest day (e.g., a Sunday holiday observed on Monday). The package has no concept of "observed date vs. raw date."

This spec adds:
1. A `RecurringHoliday` model + table for annually recurring gregorian holidays.
2. A configurable substitute-day mechanism that applies to both one-off and recurring holidays.
3. Validity windows (`effective_from`, `effective_until`) on recurring rows, so a retired holiday still excludes historical calc ranges but not current ones.

## Design choices (resolved during brainstorming)

- **Separate table** for recurring holidays, not a flag on the existing `holidays` table. Keeps each model's semantics clean — `Holiday.date` stays NOT NULL and unambiguous; `RecurringHoliday` carries `month` + `day` without a fake year.
- **Substitute eligibility is package-level config**, keyed by day-of-week, not inferred from the schedule. Same Fri-Sat weekend has different substitute days in Kelantan/Terengganu vs. Kedah — the schedule cannot disambiguate.
- **Per-row opt-in** via `observes_substitute` boolean on both `Holiday` and `RecurringHoliday`. The config tells which day-of-week is eligible; the row tells whether *this* holiday participates. Both must be true for substitution to fire.
- **No chain-forward through other holidays.** Collisions collapse — if a substituted holiday lands on a day that's already a holiday, both share that day. Consumers wanting "Tuesday off because Mon is also a holiday" must add a Tuesday row explicitly. Explicit beats implicit.
- **Substitute always advances to the next working day** (multi-day skip). Kedah needs `Fri → Sat (off) → Sun (working)`.
- **Movement recompute concern:** addressed for free by existing architecture. `Movement::saving` stores `period` and `hours` at completion time. Past completed movements are frozen — new holiday rows never retroactively change them. Documented in README, no new code.
- **Holiday model's `getTable()` gap:** fixed in this feature, paralleling the recent `kpi.models.holiday` fix (also slated for v3.1). Both models will honor `kpi.tables.*` going forward.

## Data model

### New table: `recurring_holidays`

```php
Schema::create($this->table, function (Blueprint $table) {
    $table->id();

    $table->string('name');
    $table->unsignedTinyInteger('month');         // 1..12
    $table->unsignedTinyInteger('day');           // 1..31
    $table->boolean('observes_substitute')->default(false);
    $table->date('effective_from')->nullable();   // inclusive lower bound, null = unbounded
    $table->date('effective_until')->nullable();  // inclusive upper bound, null = unbounded

    $table->timestamps();
    $table->softDeletes();
});
```

### `holidays` table — one new column

```php
Schema::table($this->table, function (Blueprint $table) {
    $table->boolean('observes_substitute')->default(false)->after('date');
});
```

`->after('date')` is honored by MySQL and silently ignored by SQLite/Postgres — safe everywhere.

### Field semantics

- **`month` / `day`** (recurring only) — gregorian month/day. Combined with each year in the calc range to produce concrete occurrences. Invalid combinations (Feb 29 in non-leap years) are skipped silently.
- **`observes_substitute`** (both tables) — when `true` AND the row's day-of-week is in `kpi.substitute` AND that day is non-working in the active schedule, the calculator observes this holiday on the next working day instead of the raw date.
- **`effective_from`** (recurring only) — inclusive lower bound. An occurrence is included iff `occurrence_date >= effective_from`. Null = no lower bound. Use case: a holiday established in 2020 shouldn't appear in a 2015 calc.
- **`effective_until`** (recurring only) — inclusive upper bound. Use case: a retired holiday still appears in historical calcs but not current ones.

### Config additions (`config/kpi.php`)

```php
'tables' => [
    'movements'           => 'movements',
    'holidays'            => 'holidays',
    'recurring_holidays'  => 'recurring_holidays',
],

'models' => [
    'movement'           => Movement::class,
    'holiday'            => Holiday::class,
    'recurring_holiday'  => RecurringHoliday::class,
],

// Day-of-week values whose holidays substitute forward to the next working day
// when observes_substitute = true on the row. Default empty = no substitution.
// Malaysian state examples:
//   Sat-Sun states + post-2025 Johor → [Day::SUNDAY->value]
//   Kelantan, Terengganu             → [Day::SATURDAY->value]
//   Kedah                            → [Day::FRIDAY->value]
'substitute' => [],
```

## Substitute algorithm

Substitute resolution is **per calc invocation** — the active `$schedules` collection (which may be the configured default or a per-call override) determines what "non-working" means.

```
foreach raw occurrence in collected set:
    date = raw_date

    if row.observes_substitute
       AND date.dayOfWeek IS IN kpi.substitute
       AND date.dayOfWeek IS NON-WORKING in active schedule:

        for i in 0..6:
            date = date + 1 day
            if date.dayOfWeek IS WORKING in active schedule:
                break
        if i == 7:
            date = raw_date   # safety: schedule has no working day → no-op fall-back

    push date to observedDates
```

Single-pass, order-independent. No chain-forward through other holidays — collisions in `observedDates` collapse at the calc loop's `contains(isSameDay)` check.

### Walkthrough against Malaysian state configurations

| Holiday lands on | Sat-Sun config | Kel/Trg config | Kedah config |
|---|---|---|---|
| Friday | not eligible → no sub | not eligible → no sub | **eligible** → push Sat (off) → Sun (working) → **Sun** |
| Saturday | not eligible → no sub | **eligible** → push Sun (working) → **Sun** | not eligible → no sub |
| Sunday | **eligible** → push Mon (working) → **Mon** | not eligible → no sub | not eligible → no sub |

The misconfig guard ("substitute only fires if the raw day is non-working") is an additional safety net — if a consumer mistakenly listed a working day in `kpi.substitute`, holidays on that day would not get pushed. It's vacuously satisfied in all three Malaysian configs above.

### Collision case (Terengganu, Sat + Sun both holidays)

- Holiday A (Sat, `observes_substitute=true`): Sat eligible, push Sat → Sun (working) → land on **Sun**.
- Holiday B (Sun, eligibility irrelevant): stays on **Sun**.
- `observedDates = [Sun, Sun]` → calc loop's `contains(isSameDay)` dedups → **Sun excluded once**.
- **Monday is working** (no holiday row for it). Matches the agreed simplified rule.

### Known limitation: federal chain-forward

The Holidays Act 1951 federal rule says: "if a public holiday falls on Sunday, substitute to Monday — *or the next working day if Monday itself is a public holiday*." This package does **not** chain-forward. If a consumer needs the chained behavior (Sun + Mon both holidays → Mon excluded, Tue also excluded), they must add a Tuesday holiday row explicitly. This trade-off favors algorithmic simplicity and explicit configuration over implicit chaining.

## Calculator integration

`src/KPI.php` changes from "fetch dates, loop" to "fetch occurrences, resolve observed dates, loop."

```php
$holidayModel   = config('kpi.models.holiday');
$recurringModel = config('kpi.models.recurring_holiday');
$substituteDays = collect(config('kpi.substitute', []))->flip();

// Widen the query window by 7 days back so a Dec-31 holiday whose substitute
// lands on Jan-1 is captured when calc starts at Jan-1.
$queryStart = $start->copy()->subDays(7);

$oneOffOccurrences = $holidayModel::query()
    ->range($queryStart, $end)
    ->get(['date', 'observes_substitute'])
    ->map(fn ($h) => [
        'date' => $h->date->toImmutable(),
        'observes_substitute' => (bool) $h->observes_substitute,
    ]);

$recurringOccurrences = $recurringModel::query()
    ->effectiveIn($queryStart, $end)
    ->get()
    ->flatMap(fn ($r) => $r->occurrencesIn($queryStart, $end)->map(fn ($d) => [
        'date' => $d->toImmutable(),
        'observes_substitute' => (bool) $r->observes_substitute,
    ]));

$observedDates = $oneOffOccurrences
    ->concat($recurringOccurrences)
    ->map(function (array $occ) use ($schedules, $substituteDays) {
        $date = $occ['date'];

        if ($occ['observes_substitute']
            && $substituteDays->has($date->dayOfWeek)
            && empty($schedules[$date->dayOfWeek])
        ) {
            for ($i = 0; $i < 7; $i++) {
                $date = $date->addDay();
                if (! empty($schedules[$date->dayOfWeek])) break;
            }
            if ($i === 7) $date = $occ['date'];
        }

        return $date;
    });

$excludeDates = collect([
    ...collect($excludeDates)->map(fn ($d) => Carbon::parse($d)),
    ...$observedDates,
]);
```

The main `while ($step < $end)` loop is unchanged.

## Model surface

### `src/Models/RecurringHoliday.php` (new)

```php
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
            'month'               => 'integer',
            'day'                 => 'integer',
            'observes_substitute' => 'boolean',
            'effective_from'      => 'date',
            'effective_until'     => 'date',
        ];
    }

    public function getTable(): string
    {
        return config('kpi.tables.recurring_holidays', parent::getTable());
    }

    public function scopeEffectiveIn(Builder $query, Carbon|string $start, Carbon|string|null $end = null): void
    {
        $start = Carbon::parse($start);
        $end   = Carbon::parse($end ?? now());

        $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $end))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $start));
    }

    public function occurrencesIn(Carbon|string $start, Carbon|string $end): Collection
    {
        $start = Carbon::parse($start);
        $end   = Carbon::parse($end);

        return collect(range($start->year, $end->year))
            ->filter(fn (int $year) => checkdate($this->month, $this->day, $year))
            ->map(fn (int $year) => Carbon::create($year, $this->month, $this->day))
            ->filter(fn (Carbon $date) =>
                (! $this->effective_from  || $date->gte($this->effective_from)) &&
                (! $this->effective_until || $date->lte($this->effective_until)) &&
                $date->between($start, $end)
            )
            ->values();
    }
}
```

The model owns its expansion logic via `occurrencesIn()` — keeps leap-year handling and validity-window enforcement testable in isolation from the calculator.

### `src/Models/Holiday.php` (changes)

```diff
 protected $fillable = [
     'name',
     'date',
+    'observes_substitute',
 ];

 protected function casts(): array
 {
     return [
         'date' => 'date',
+        'observes_substitute' => 'boolean',
     ];
 }

+public function getTable(): string
+{
+    return config('kpi.tables.holidays', parent::getTable());
+}
```

### `database/factories/RecurringHolidayFactory.php` (new)

```php
namespace Hasyirin\KPI\Database\Factories;

use Hasyirin\KPI\Models\RecurringHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringHolidayFactory extends Factory
{
    protected $model = RecurringHoliday::class;

    public function definition(): array
    {
        return [
            'name'                => fake()->word(),
            'month'               => fake()->numberBetween(1, 12),
            'day'                 => fake()->numberBetween(1, 28),   // avoids leap-year / 31-day edges
            'observes_substitute' => false,
            'effective_from'      => null,
            'effective_until'     => null,
        ];
    }
}
```

No `HolidayFactory` shipped — matches existing state. Can add later if asked.

## Migrations & service provider

### Two new migration stubs

**`database/migrations/create_recurring_holidays_table.php.stub`** — creates the new table (see schema above).

**`database/migrations/add_observes_substitute_to_holidays_table.php.stub`** — adds the new column to the existing table (see schema above).

Both read their target table name from `config('kpi.tables.*')` in their constructor, matching the existing `create_holidays_table` pattern.

### Service provider registration

```diff
 ->hasMigrations([
     'create_movements_table',
     'create_holidays_table',
     'add_parent_child_to_movements_table',
+    'add_observes_substitute_to_holidays_table',
+    'create_recurring_holidays_table',
 ]);
```

## Upgrade path (existing v3.x consumers)

Fully additive — no breaking changes:
- New `holidays.observes_substitute` column defaults to `false` → existing rows behave identically to today.
- `kpi.substitute` config defaults to empty array → no substitute resolution fires by default.
- `recurring_holidays` table is empty until populated.
- All existing tests pass unmodified.

```bash
composer update hasyirin/laravel-kpi

# Pick up the two new migration stubs
php artisan vendor:publish --tag="laravel-kpi-migrations"

# Apply them
php artisan migrate

# (Optional) re-publish config to see the new 'substitute' and recurring_holiday keys
php artisan vendor:publish --tag="laravel-kpi-config" --force
# — or manually merge the three new keys into the existing config/kpi.php
```

**Custom-model caveat:** consumers who've overridden `kpi.models.holiday` with their own subclass must add `observes_substitute` to that subclass's `$fillable` and `$casts`. Documented in CHANGELOG upgrade guide.

## Test coverage

### Extends `tests/CalculationTest.php`

Recurring expansion:

1. Excludes a recurring holiday on the same month/day in each year of the range.
2. Skips a recurring Feb 29 in non-leap years and applies it in leap years.
3. Ignores a recurring holiday whose `effective_until` is before the range.
4. Ignores a recurring holiday whose `effective_from` is after the range.
5. Combines one-off and recurring holidays.

Substitute logic:

6. Does not substitute when `observes_substitute` is false (default).
7. Does not substitute when the day-of-week is not in `kpi.substitute`.
8. Substitutes Sunday holiday to Monday under Sat-Sun config (`substitute = [SUN]`).
9. Substitutes Saturday holiday to Sunday under Kelantan/Terengganu config (`substitute = [SAT]`, schedule Sun-Thu).
10. Substitutes Friday holiday past Saturday to Sunday under Kedah config (`substitute = [FRI]`, schedule Sun-Thu).
11. Collapses collision when Sat substitutes onto an existing Sunday holiday in Terengganu (Sun excluded once, Mon stays working).
12. Does not substitute when the raw day is a working day, even if in `kpi.substitute` (misconfig guard).

Substitute + recurring combined:

13. Substitutes a recurring holiday occurrence per year independently.

### New file `tests/RecurringHolidayTest.php`

Model unit tests in isolation from the calculator:

14. `effectiveIn` returns rows with null bounds.
15. `effectiveIn` excludes rows whose `effective_until` is before the range.
16. `effectiveIn` excludes rows whose `effective_from` is after the range.
17. `effectiveIn` includes rows whose window partially overlaps the range.
18. `occurrencesIn` expands one date per year in range.
19. `occurrencesIn` skips Feb 29 in non-leap years.
20. `occurrencesIn` honors `effective_from`.
21. `occurrencesIn` honors `effective_until`.
22. `occurrencesIn` returns empty for an out-of-range query.
23. `Holiday::getTable` honors `config('kpi.tables.holidays')` override.
24. `RecurringHoliday::getTable` honors `config('kpi.tables.recurring_holidays')` override.

### Regression bar (must stay green unmodified)

- All 12 tests in `tests/CalculationTest.php` — they don't set `observes_substitute` or touch `kpi.substitute`.
- `tests/ArchTest.php`, `tests/DataObjectTest.php`, `tests/MovementTest.php`, `tests/ExampleTest.php` — no overlap with this feature.

## CHANGELOG entry (draft for the v3.1.0 release)

```
## v3.1.0 — fixed/recurring public holidays + substitute-day support

### Added
- `RecurringHoliday` model + `recurring_holidays` table for annually recurring holidays
  (e.g., Labour Day, National Day). `effective_from` / `effective_until` bound applicability.
- `observes_substitute` column on both `holidays` and `recurring_holidays`. When set and the
  holiday lands on a configured-eligible day, the calculator observes it on the next
  working day. Multi-day skips (Kedah Fri → Sun) supported; no chain-forward through
  other holidays — collisions collapse onto the same day.
- `kpi.substitute` config: list of day-of-week values eligible for substitution.

### Fixed
- `Holiday::getTable()` now honors `config('kpi.tables.holidays')` (parity with the
  migration, which already did).

### Upgrade
- Run `vendor:publish --tag="laravel-kpi-migrations"` then `migrate`.
- If you've overridden `kpi.models.holiday`, add `observes_substitute` to its
  `$fillable` and `$casts`.
```

## Research references (Malaysian substitute-day rules)

Sources consulted during design to validate the substitute algorithm against Malaysian government practice:

- [Public holidays in Malaysia – Wikipedia](https://en.wikipedia.org/wiki/Public_holidays_in_Malaysia)
- [Public Holidays under Malaysian Law (Updated 2025) – AJobThing](https://www.ajobthing.com/resources/blog/public-holidays-under-malaysian-law)
- [Substitute Holidays & Replacement Leave in Malaysia – AJobThing](https://www.ajobthing.com/resources/blog/substitute-holidays-vs-replacement-leave-malaysia)
- [Holidays Act 1951 (Act 369) – Kabinet.gov.my PDF](https://www.kabinet.gov.my/storage/2024/11/1951_12_31_act369.pdf)
- [Understanding Varying Weekend Patterns Across Malaysian States](https://www.malaysiapublicholiday.my/en/blog/understanding-the-varying-weekend-patterns-across-malaysian-states-origins-and-impacts)
