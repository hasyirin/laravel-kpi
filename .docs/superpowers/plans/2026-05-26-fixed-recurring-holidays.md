# Fixed/Recurring Public Holidays Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add support for annually recurring gregorian holidays (Labour Day, National Day, etc.) with optional next-working-day substitution that honors Malaysian state-specific rules.

**Architecture:** New `RecurringHoliday` model + `recurring_holidays` table sit beside the existing `Holiday` model. Both models carry an `observes_substitute` flag. The calculator gathers one-off + recurring occurrences, then per-row applies a config-driven substitute resolution (`kpi.substitute` lists eligible day-of-week values). Substitution skips forward past non-working days; collisions across holidays collapse onto the same day (no chain-forward). Fully additive — defaults preserve existing v3.0 behavior.

**Tech Stack:** PHP 8.4+, Laravel 12/13, Eloquent, Pest, Orchestra Testbench, Spatie LaravelPackageTools, PHPStan.

**Reference:** `.docs/superpowers/specs/2026-05-26-fixed-recurring-holidays-design.md`

---

## File Structure

**New files:**
- `database/migrations/add_observes_substitute_to_holidays_table.php.stub` — adds nullable boolean column to existing holidays table.
- `database/migrations/create_recurring_holidays_table.php.stub` — creates the new recurring_holidays table.
- `src/Models/RecurringHoliday.php` — Eloquent model with `effectiveIn` scope and `occurrencesIn` expansion method.
- `database/factories/RecurringHolidayFactory.php` — test factory.
- `tests/RecurringHolidayTest.php` — model unit tests (scope, occurrencesIn, getTable).

**Modified files:**
- `src/Models/Holiday.php` — add `observes_substitute` to fillable/casts and add `getTable()` override.
- `src/KPI.php` — new holiday-loading + substitute resolution logic; main calc loop unchanged.
- `src/KPIServiceProvider.php` — register two new migrations.
- `config/kpi.php` — three new keys (`tables.recurring_holidays`, `models.recurring_holiday`, `substitute`).
- `tests/TestCase.php` — append two new migrations to the hardcoded array.
- `tests/CalculationTest.php` — add ~13 tests for recurring + substitute behavior.
- `README.md` — document recurring holidays + substitute usage.

---

## Task 1: Add migration for `holidays.observes_substitute` column

**Files:**
- Create: `database/migrations/add_observes_substitute_to_holidays_table.php.stub`

- [ ] **Step 1: Create the migration stub**

Create `database/migrations/add_observes_substitute_to_holidays_table.php.stub` with this content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected string $table;

    public function __construct()
    {
        $this->table = config('kpi.tables.holidays');
    }

    public function up(): void
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->boolean('observes_substitute')->default(false)->after('date');
        });
    }

    public function down(): void
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn('observes_substitute');
        });
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/add_observes_substitute_to_holidays_table.php.stub
git commit -m "Add observes_substitute column migration for holidays table"
```

---

## Task 2: Add migration for `recurring_holidays` table

**Files:**
- Create: `database/migrations/create_recurring_holidays_table.php.stub`

- [ ] **Step 1: Create the migration stub**

Create `database/migrations/create_recurring_holidays_table.php.stub` with this content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected string $table;

    public function __construct()
    {
        $this->table = config('kpi.tables.recurring_holidays');
    }

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->boolean('observes_substitute')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/create_recurring_holidays_table.php.stub
git commit -m "Add recurring_holidays table migration"
```

---

## Task 3: Scaffold `RecurringHoliday` model

**Files:**
- Create: `src/Models/RecurringHoliday.php`

This task creates the model class first so that subsequent tasks can reference `RecurringHoliday::class` in config and use statements without compile-time errors. No tests run here yet — nothing references the model.

- [ ] **Step 1: Create the model scaffold**

Create `src/Models/RecurringHoliday.php`:

```php
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
```

- [ ] **Step 2: Run PHPStan to ensure the new class is syntactically clean**

Run: `vendor/bin/phpstan analyse src/Models/RecurringHoliday.php --no-progress`
Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add src/Models/RecurringHoliday.php
git commit -m "Scaffold RecurringHoliday model"
```

---

## Task 4: Add new config keys to `config/kpi.php`

**Files:**
- Modify: `config/kpi.php`

- [ ] **Step 1: Update the config file**

Edit `config/kpi.php`. First update the `use` statements at the top to add the new model:

```php
use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Models\RecurringHoliday;
```

Then update `tables` to add `recurring_holidays`:

```php
'tables' => [
    'movements' => 'movements',
    'holidays' => 'holidays',
    'recurring_holidays' => 'recurring_holidays',
],
```

Then update `models` to add `recurring_holiday`:

```php
'models' => [
    'movement' => Movement::class,
    'holiday' => Holiday::class,
    'recurring_holiday' => RecurringHoliday::class,
],
```

Then add a new `substitute` section after `schedule` (or wherever it fits):

```php
// Day-of-week values whose holidays substitute forward to the next working day
// when observes_substitute = true on the row. Default empty = no substitution.
// Malaysian state examples:
//   Sat-Sun states + post-2025 Johor → [Day::SUNDAY->value]
//   Kelantan, Terengganu             → [Day::SATURDAY->value]
//   Kedah                            → [Day::FRIDAY->value]
'substitute' => [],
```

- [ ] **Step 2: Run the existing test suite to verify nothing broke**

Run: `vendor/bin/pest`
Expected: All existing tests still pass. The new keys are present but no migrations yet reference the recurring table from the test harness, so behavior is unchanged.

- [ ] **Step 3: Commit**

```bash
git add config/kpi.php
git commit -m "Add recurring_holidays and substitute config keys"
```

---

## Task 5: Register both migrations in service provider + test harness

**Files:**
- Modify: `src/KPIServiceProvider.php`
- Modify: `tests/TestCase.php`

- [ ] **Step 1: Update service provider**

Edit `src/KPIServiceProvider.php`. Replace the `hasMigrations([...])` array with:

```php
->hasMigrations([
    'create_movements_table',
    'create_holidays_table',
    'add_parent_child_to_movements_table',
    'add_observes_substitute_to_holidays_table',
    'create_recurring_holidays_table',
]);
```

- [ ] **Step 2: Update test harness**

Edit `tests/TestCase.php`. Replace the `$migrations` array inside `getEnvironmentSetUp()` with:

```php
$migrations = [
    'create_holidays_table',
    'add_observes_substitute_to_holidays_table',
    'create_movements_table',
    'add_parent_child_to_movements_table',
    'create_recurring_holidays_table',
];
```

The `add_observes_substitute_to_holidays_table` migration must come *after* `create_holidays_table` because it ALTERs that table.

- [ ] **Step 3: Run the existing test suite**

Run: `vendor/bin/pest`
Expected: All existing tests pass. The new migrations run in test setup and create the additional schema, but no existing test references the new column or table so behavior is unchanged.

- [ ] **Step 4: Commit**

```bash
git add src/KPIServiceProvider.php tests/TestCase.php
git commit -m "Register fixed/recurring holiday migrations in provider and test harness"
```

---

## Task 6: Create `RecurringHolidayFactory`

**Files:**
- Create: `database/factories/RecurringHolidayFactory.php`

- [ ] **Step 1: Create the factory**

Create `database/factories/RecurringHolidayFactory.php`:

```php
<?php

namespace Hasyirin\KPI\Database\Factories;

use Hasyirin\KPI\Models\RecurringHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringHolidayFactory extends Factory
{
    protected $model = RecurringHoliday::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'month' => fake()->numberBetween(1, 12),
            'day' => fake()->numberBetween(1, 28),
            'observes_substitute' => false,
            'effective_from' => null,
            'effective_until' => null,
        ];
    }
}
```

`day` is capped at 28 to avoid leap-year and 31-day-month edge cases in random tests.

- [ ] **Step 2: Commit**

```bash
git add database/factories/RecurringHolidayFactory.php
git commit -m "Add RecurringHolidayFactory"
```

---

## Task 7: Verify baseline — full test suite + PHPStan green

**Files:** none (verification only).

- [ ] **Step 1: Run Pest**

Run: `vendor/bin/pest`
Expected: All existing tests pass (12 calc + ArchTest + DataObject + Movement + Example).

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: No errors.

If either fails, stop and fix before proceeding to Task 8.

---

## Task 8: Add `observes_substitute` to `Holiday` model (TDD)

**Files:**
- Modify: `src/Models/Holiday.php`
- Test: `tests/RecurringHolidayTest.php` (created here, expanded in Task 12 and later)

- [ ] **Step 1: Create the test file with a failing test**

Create `tests/RecurringHolidayTest.php`:

```php
<?php

use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\RecurringHoliday;
use Illuminate\Support\Carbon;

it('persists observes_substitute on Holiday', function () {
    $holiday = Holiday::create([
        'name' => 'Test',
        'date' => '2026-01-01',
        'observes_substitute' => true,
    ]);

    expect($holiday->fresh()->observes_substitute)->toBeTrue();
});
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php`
Expected: FAIL — the new field is not in fillable, so `observes_substitute` returns `null` from `fresh()` (or remains unset).

- [ ] **Step 3: Update `Holiday` fillable and casts**

Edit `src/Models/Holiday.php`. Update `$fillable`:

```php
protected $fillable = [
    'name',
    'date',
    'observes_substitute',
];
```

Update `casts()`:

```php
protected function casts(): array
{
    return [
        'date' => 'date',
        'observes_substitute' => 'boolean',
    ];
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php`
Expected: PASS.

- [ ] **Step 5: Run full suite to verify no regressions**

Run: `vendor/bin/pest`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Models/Holiday.php tests/RecurringHolidayTest.php
git commit -m "Add observes_substitute to Holiday fillable and casts"
```

---

## Task 9: Add `Holiday::getTable()` honoring config (TDD)

**Files:**
- Modify: `src/Models/Holiday.php`
- Test: `tests/RecurringHolidayTest.php`

- [ ] **Step 1: Add failing test**

Append to `tests/RecurringHolidayTest.php`:

```php
it('honors config(kpi.tables.holidays) override on Holiday', function () {
    config(['kpi.tables.holidays' => 'my_custom_holidays']);

    expect((new Holiday)->getTable())->toBe('my_custom_holidays');
});

it('honors config(kpi.tables.recurring_holidays) override on RecurringHoliday', function () {
    config(['kpi.tables.recurring_holidays' => 'my_custom_recurring']);

    expect((new RecurringHoliday)->getTable())->toBe('my_custom_recurring');
});
```

- [ ] **Step 2: Run tests, expect failure on the Holiday case (RecurringHoliday already passes)**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php --filter="honors config"`
Expected: One PASS (RecurringHoliday), one FAIL (Holiday — returns `holidays`, not `my_custom_holidays`).

- [ ] **Step 3: Add `getTable()` override to Holiday**

Edit `src/Models/Holiday.php`. Add this method:

```php
public function getTable(): string
{
    return config('kpi.tables.holidays', parent::getTable());
}
```

- [ ] **Step 4: Run tests, expect both pass**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php --filter="honors config"`
Expected: Both PASS.

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Models/Holiday.php tests/RecurringHolidayTest.php
git commit -m "Honor kpi.tables.holidays config on Holiday model"
```

---

## Task 10: Implement `RecurringHoliday::scopeEffectiveIn` (TDD)

**Files:**
- Modify: `src/Models/RecurringHoliday.php`
- Test: `tests/RecurringHolidayTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/RecurringHolidayTest.php`:

```php
it('effectiveIn returns rows with null bounds', function () {
    RecurringHoliday::factory()->create(['name' => 'A', 'month' => 5, 'day' => 1]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2020-01-01', '2030-12-31')
        ->get();

    expect($results)->toHaveCount(1);
});

it('effectiveIn excludes rows whose effective_until is before the range', function () {
    RecurringHoliday::factory()->create([
        'name' => 'Retired',
        'month' => 5,
        'day' => 1,
        'effective_until' => '2024-12-31',
    ]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2025-01-01', '2025-12-31')
        ->get();

    expect($results)->toBeEmpty();
});

it('effectiveIn excludes rows whose effective_from is after the range', function () {
    RecurringHoliday::factory()->create([
        'name' => 'Future',
        'month' => 5,
        'day' => 1,
        'effective_from' => '2030-01-01',
    ]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2025-01-01', '2025-12-31')
        ->get();

    expect($results)->toBeEmpty();
});

it('effectiveIn includes rows whose window partially overlaps the range', function () {
    RecurringHoliday::factory()->create([
        'name' => 'Bounded',
        'month' => 5,
        'day' => 1,
        'effective_from' => '2020-01-01',
        'effective_until' => '2025-06-30',
    ]);

    $results = RecurringHoliday::query()
        ->effectiveIn('2025-01-01', '2025-12-31')
        ->get();

    expect($results)->toHaveCount(1);
});
```

- [ ] **Step 2: Run tests, expect failure**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php --filter="effectiveIn"`
Expected: FAIL — `BadMethodCallException: Call to undefined method ... effectiveIn()`.

- [ ] **Step 3: Implement the scope**

Edit `src/Models/RecurringHoliday.php`. Add this method:

```php
public function scopeEffectiveIn(Builder $query, Carbon|string $start, Carbon|string|null $end = null): void
{
    $start = Carbon::parse($start);
    $end = Carbon::parse($end ?? now());

    $query
        ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $end))
        ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $start));
}
```

- [ ] **Step 4: Run tests, expect pass**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php --filter="effectiveIn"`
Expected: All four PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecurringHoliday.php tests/RecurringHolidayTest.php
git commit -m "Add RecurringHoliday effectiveIn scope"
```

---

## Task 11: Implement `RecurringHoliday::occurrencesIn` (TDD)

**Files:**
- Modify: `src/Models/RecurringHoliday.php`
- Test: `tests/RecurringHolidayTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/RecurringHolidayTest.php`:

```php
it('occurrencesIn expands one date per year in range', function () {
    $h = RecurringHoliday::factory()->create(['month' => 5, 'day' => 1]);

    $dates = $h->occurrencesIn('2023-01-01', '2025-12-31');

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->format('Y-m-d'))->toBe('2023-05-01')
        ->and($dates[1]->format('Y-m-d'))->toBe('2024-05-01')
        ->and($dates[2]->format('Y-m-d'))->toBe('2025-05-01');
});

it('occurrencesIn skips Feb 29 in non-leap years', function () {
    $h = RecurringHoliday::factory()->create(['month' => 2, 'day' => 29]);

    $dates = $h->occurrencesIn('2023-01-01', '2026-12-31');

    // 2024 is leap; 2023, 2025, 2026 are not.
    expect($dates)->toHaveCount(1)
        ->and($dates[0]->format('Y-m-d'))->toBe('2024-02-29');
});

it('occurrencesIn honors effective_from', function () {
    $h = RecurringHoliday::factory()->create([
        'month' => 5,
        'day' => 1,
        'effective_from' => '2024-06-15',  // after May 1 2024, so 2024 occurrence excluded
    ]);

    $dates = $h->occurrencesIn('2023-01-01', '2025-12-31');

    expect($dates)->toHaveCount(1)
        ->and($dates[0]->format('Y-m-d'))->toBe('2025-05-01');
});

it('occurrencesIn honors effective_until', function () {
    $h = RecurringHoliday::factory()->create([
        'month' => 5,
        'day' => 1,
        'effective_until' => '2024-12-31',
    ]);

    $dates = $h->occurrencesIn('2023-01-01', '2025-12-31');

    expect($dates)->toHaveCount(2)
        ->and($dates[0]->format('Y-m-d'))->toBe('2023-05-01')
        ->and($dates[1]->format('Y-m-d'))->toBe('2024-05-01');
});

it('occurrencesIn returns empty for an out-of-range query', function () {
    $h = RecurringHoliday::factory()->create(['month' => 5, 'day' => 1]);

    // May 1 not in Jan range.
    $dates = $h->occurrencesIn('2025-01-01', '2025-01-31');

    expect($dates)->toBeEmpty();
});
```

- [ ] **Step 2: Run tests, expect failure**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php --filter="occurrencesIn"`
Expected: FAIL — `Error: Call to undefined method ... occurrencesIn()`.

- [ ] **Step 3: Implement the method**

Edit `src/Models/RecurringHoliday.php`. Add this method:

```php
public function occurrencesIn(Carbon|string $start, Carbon|string $end): Collection
{
    $start = Carbon::parse($start);
    $end = Carbon::parse($end);

    return collect(range($start->year, $end->year))
        ->filter(fn (int $year) => checkdate($this->month, $this->day, $year))
        ->map(fn (int $year) => Carbon::create($year, $this->month, $this->day))
        ->filter(fn (Carbon $date) =>
            (! $this->effective_from || $date->gte($this->effective_from)) &&
            (! $this->effective_until || $date->lte($this->effective_until)) &&
            $date->between($start, $end)
        )
        ->values();
}
```

- [ ] **Step 4: Run tests, expect pass**

Run: `vendor/bin/pest tests/RecurringHolidayTest.php --filter="occurrencesIn"`
Expected: All five PASS.

- [ ] **Step 5: Run full suite + PHPStan**

Run: `vendor/bin/pest`
Expected: All tests pass.

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecurringHoliday.php tests/RecurringHolidayTest.php
git commit -m "Add RecurringHoliday occurrencesIn expansion method"
```

---

## Task 12: Calculator — load and expand recurring holidays (TDD)

**Files:**
- Modify: `src/KPI.php`
- Test: `tests/CalculationTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/CalculationTest.php`:

```php
use Hasyirin\KPI\Models\RecurringHoliday;

it('excludes a recurring holiday on the same month/day in each year of the range', function () {
    // Labour Day May 1 — recurring annually
    RecurringHoliday::create(['name' => 'Labour Day', 'month' => 5, 'day' => 1]);

    // Two consecutive years, calc on May 1 in each
    $kpi2025 = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-02 17:00'));
    $kpi2026 = KPI::calculate(Carbon::parse('2026-04-30 08:00'), Carbon::parse('2026-05-02 17:00'));

    // 2025-05-01 is a Thursday, 2026-05-01 is a Friday — both scheduled days
    expect($kpi2025->metadata->excluded)->toBe(1)
        ->and($kpi2026->metadata->excluded)->toBe(1);
});

it('skips a recurring Feb 29 in non-leap years and applies it in leap years', function () {
    RecurringHoliday::create(['name' => 'Leap Day', 'month' => 2, 'day' => 29]);

    // 2024 is leap (Feb 29 = Thursday, scheduled). 2025 is not.
    $kpi2024 = KPI::calculate(Carbon::parse('2024-02-28 08:00'), Carbon::parse('2024-03-01 17:00'));
    $kpi2025 = KPI::calculate(Carbon::parse('2025-02-27 08:00'), Carbon::parse('2025-02-28 17:00'));

    expect($kpi2024->metadata->excluded)->toBe(1)   // Feb 29 2024 excluded
        ->and($kpi2025->metadata->excluded)->toBe(0); // no Feb 29 in 2025
});

it('ignores a recurring holiday whose effective_until is before the range', function () {
    RecurringHoliday::create([
        'name' => 'Retired',
        'month' => 5,
        'day' => 1,
        'effective_until' => '2024-12-31',
    ]);

    $kpi = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-02 17:00'));

    expect($kpi->metadata->excluded)->toBe(0);
});

it('ignores a recurring holiday whose effective_from is after the range', function () {
    RecurringHoliday::create([
        'name' => 'Future',
        'month' => 5,
        'day' => 1,
        'effective_from' => '2030-01-01',
    ]);

    $kpi = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-02 17:00'));

    expect($kpi->metadata->excluded)->toBe(0);
});

it('combines one-off and recurring holidays', function () {
    Holiday::create(['name' => 'One-off', 'date' => '2025-05-01']);
    RecurringHoliday::create(['name' => 'Recurring', 'month' => 5, 'day' => 2]);

    $kpi = KPI::calculate(Carbon::parse('2025-04-30 08:00'), Carbon::parse('2025-05-05 17:00'));

    // May 1 (Thu, one-off) + May 2 (Fri, recurring) excluded; May 3-4 unscheduled weekend
    expect($kpi->metadata->excluded)->toBe(2);
});
```

- [ ] **Step 2: Run tests, expect failure**

Run: `vendor/bin/pest tests/CalculationTest.php --filter="recurring"`
Expected: FAIL — calculator doesn't read `RecurringHoliday`.

- [ ] **Step 3: Update `src/KPI.php` calculator to load recurring holidays**

Edit `src/KPI.php`. Replace the entire body of `calculate()` so the relevant block reads:

```php
public function calculate(
    Carbon|string $start,
    Carbon|string|null $end = null,
    Arrayable|array $excludeDates = [],
    Arrayable|array $schedules = [],
): KPIData {

    if (empty($schedules)) {
        $schedules = collect(config('kpi.schedule'))->map(fn (array $data) => WorkSchedule::parse($data));
    }

    $schedules = collect($schedules);

    $total = ['period' => 0, 'minutes' => 0, 'scheduled' => 0, 'unscheduled' => 0, 'excluded' => 0];

    $start = CarbonImmutable::parse($start);
    $end = CarbonImmutable::parse($end ?? now());

    // Widen the holiday query window by 7 days to capture late-December substitutes
    // whose observed date may roll into the start of the calc range.
    $queryStart = $start->subDays(7);

    $holiday = config('kpi.models.holiday');
    $recurring = config('kpi.models.recurring_holiday');

    $oneOffOccurrences = $holiday::query()
        ->range($queryStart, $end)
        ->get(['date', 'observes_substitute'])
        ->map(fn ($h) => [
            'date' => $h->date->toImmutable(),
            'observes_substitute' => (bool) $h->observes_substitute,
        ]);

    $recurringOccurrences = $recurring::query()
        ->effectiveIn($queryStart, $end)
        ->get()
        ->flatMap(fn ($r) => $r->occurrencesIn($queryStart, $end)->map(fn ($d) => [
            'date' => $d->toImmutable(),
            'observes_substitute' => (bool) $r->observes_substitute,
        ]));

    $observedDates = $oneOffOccurrences->concat($recurringOccurrences)->pluck('date');

    $excludeDates = collect([
        ...collect($excludeDates)->map(fn (Carbon|string $date) => Carbon::parse($date)),
        ...$observedDates,
    ]);

    $minutes = 0;
    $step = $start;

    while ($step < $end) {
        if (empty($schedules[$step->dayOfWeek])) {
            $step = $step->addDay()->startOfDay();
            $total['unscheduled'] += 1;

            continue;
        }

        if ($excludeDates->contains(fn (Carbon $date) => $date->isSameDay($step))) {
            $step = $step->addDay()->startOfDay();
            $total['excluded'] += 1;

            continue;
        }

        /** @var WorkSchedule $schedule */
        $schedule = $schedules[$step->dayOfWeek];

        $total['minutes'] += $schedule->minutes();
        $total['scheduled'] += 1;

        $step = $this->sanitizeStartDate($schedule, $step);

        $finish = min($end, $step->setHour($schedule->end->hour)->setMinute($schedule->end->minute));

        $diff = $finish > $step ? $step->diffInMinutes($finish) : 0.0;

        $minutes += $diff;

        $period = bcdiv((string) max($diff, 0.0001), (string) $schedule->minutes(), 4);

        $total['period'] = bcadd($total['period'], $period, 4);

        $step = $finish->addDay()->startOfDay();
    }

    return KPIData::make(
        minutes: $minutes,
        hours: (float) bcdiv((string) max($minutes, 0.0001), '60', 4),
        period: $total['period'],
        metadata: KPIMetadata::make(
            minutes: $total['minutes'],
            unscheduled: $total['unscheduled'],
            scheduled: $total['scheduled'],
            excluded: $total['excluded'],
        )
    );
}
```

This is the full method. Note the `$start = CarbonImmutable::parse(...)` move to BEFORE holiday loading. Substitute logic is added in Task 13 — for now `$observedDates` is just the raw dates (no substitute resolution yet).

- [ ] **Step 4: Run recurring tests, expect pass**

Run: `vendor/bin/pest tests/CalculationTest.php --filter="recurring"`
Expected: All five new tests PASS.

- [ ] **Step 5: Run full suite + PHPStan**

Run: `vendor/bin/pest`
Expected: All tests pass (including all 12 existing CalculationTest cases — they don't set `observes_substitute` and use Holiday-only).

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/KPI.php tests/CalculationTest.php
git commit -m "Load and expand RecurringHoliday occurrences in calculator"
```

---

## Task 13: Calculator — substitute resolution with multi-day skip and misconfig guard (TDD)

**Files:**
- Modify: `src/KPI.php`
- Test: `tests/CalculationTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/CalculationTest.php`:

```php
use Hasyirin\KPI\Enums\Day;

it('does not substitute when observes_substitute is false by default', function () {
    config(['kpi.substitute' => [Day::SUNDAY->value]]);

    // Sun 2025-05-04 — non-working in default Mon-Fri schedule
    Holiday::create(['name' => 'Sun Holiday', 'date' => '2025-05-04']);

    $kpi = KPI::calculate(Carbon::parse('2025-05-02 08:00'), Carbon::parse('2025-05-06 17:00'));

    // No substitute → Monday May 5 stays scheduled (not excluded)
    expect($kpi->metadata->excluded)->toBe(0);
});

it('does not substitute when the day-of-week is not in kpi.substitute', function () {
    config(['kpi.substitute' => []]);   // empty config

    Holiday::create(['name' => 'Sun Holiday', 'date' => '2025-05-04', 'observes_substitute' => true]);

    $kpi = KPI::calculate(Carbon::parse('2025-05-02 08:00'), Carbon::parse('2025-05-06 17:00'));

    // observes_substitute=true but Sunday not eligible → no substitute
    expect($kpi->metadata->excluded)->toBe(0);
});

it('substitutes Sunday holiday to Monday under Sat-Sun config', function () {
    config(['kpi.substitute' => [Day::SUNDAY->value]]);
    // Default schedule is Mon-Fri working.

    Holiday::create(['name' => 'Sun', 'date' => '2025-05-04', 'observes_substitute' => true]);

    $kpi = KPI::calculate(Carbon::parse('2025-05-02 08:00'), Carbon::parse('2025-05-06 17:00'));

    // Sun substituted to Mon May 5 → Mon excluded
    expect($kpi->metadata->excluded)->toBe(1);
});

it('substitutes Saturday holiday to Sunday under Kelantan/Terengganu config', function () {
    config(['kpi.substitute' => [Day::SATURDAY->value]]);

    // Sun-Thu schedule; Fri-Sat off.
    $schedules = collect([
        Day::SUNDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::MONDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::TUESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::WEDNESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::THURSDAY->value => WorkSchedule::parse(['8:00', '17:00']),
    ]);

    // Sat 2025-05-03
    Holiday::create(['name' => 'Sat', 'date' => '2025-05-03', 'observes_substitute' => true]);

    $kpi = KPI::calculate(
        Carbon::parse('2025-05-02 08:00'),  // Fri (off)
        Carbon::parse('2025-05-06 17:00'),  // Tue
        schedules: $schedules,
    );

    // Sat substituted to Sun May 4 → Sun excluded
    expect($kpi->metadata->excluded)->toBe(1);
});

it('substitutes Friday holiday past Saturday to Sunday under Kedah config', function () {
    config(['kpi.substitute' => [Day::FRIDAY->value]]);

    $schedules = collect([
        Day::SUNDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::MONDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::TUESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::WEDNESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::THURSDAY->value => WorkSchedule::parse(['8:00', '17:00']),
    ]);

    // Fri 2025-05-02
    Holiday::create(['name' => 'Fri', 'date' => '2025-05-02', 'observes_substitute' => true]);

    $kpi = KPI::calculate(
        Carbon::parse('2025-04-30 08:00'),  // Wed
        Carbon::parse('2025-05-06 17:00'),  // Tue
        schedules: $schedules,
    );

    // Fri → skip Sat (off) → Sun May 4 (working in Kedah schedule) → Sun excluded
    expect($kpi->metadata->excluded)->toBe(1);
});

it('collapses collision when Sat substitutes onto Sunday holiday in Terengganu', function () {
    config(['kpi.substitute' => [Day::SATURDAY->value]]);

    $schedules = collect([
        Day::SUNDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::MONDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::TUESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::WEDNESDAY->value => WorkSchedule::parse(['8:00', '17:00']),
        Day::THURSDAY->value => WorkSchedule::parse(['8:00', '17:00']),
    ]);

    Holiday::create(['name' => 'Sat', 'date' => '2025-05-03', 'observes_substitute' => true]);
    Holiday::create(['name' => 'Sun', 'date' => '2025-05-04']);   // observes_substitute=false (Sun not eligible anyway)

    $kpi = KPI::calculate(
        Carbon::parse('2025-05-02 08:00'),
        Carbon::parse('2025-05-06 17:00'),
        schedules: $schedules,
    );

    // Sat sub → Sun. Sun raw also there. Collision → Sun excluded ONCE. Mon stays scheduled.
    expect($kpi->metadata->excluded)->toBe(1);
});

it('does not substitute when the raw day is a working day, even if in kpi.substitute', function () {
    // Misconfig: someone put Wednesday (a working day) in substitute config.
    config(['kpi.substitute' => [Day::WEDNESDAY->value]]);

    Holiday::create(['name' => 'Wed', 'date' => '2025-04-30', 'observes_substitute' => true]);   // Wed

    $kpi = KPI::calculate(Carbon::parse('2025-04-29 08:00'), Carbon::parse('2025-05-02 17:00'));

    // Wed is working → guard blocks substitute → Wed itself excluded (1), not Thu
    expect($kpi->metadata->excluded)->toBe(1);
});

it('substitutes a recurring holiday occurrence per year independently', function () {
    config(['kpi.substitute' => [Day::SUNDAY->value]]);

    // National Day Aug 31 — recurring annually, observes substitute.
    RecurringHoliday::create([
        'name' => 'National Day',
        'month' => 8,
        'day' => 31,
        'observes_substitute' => true,
    ]);

    // 2025-08-31 is a Sunday — substituted to Mon Sep 1.
    // 2026-08-31 is a Monday — no substitute needed (Mon is working).
    $kpi2025 = KPI::calculate(Carbon::parse('2025-08-29 08:00'), Carbon::parse('2025-09-02 17:00'));
    $kpi2026 = KPI::calculate(Carbon::parse('2026-08-30 08:00'), Carbon::parse('2026-09-01 17:00'));

    // 2025: Aug 31 (Sun, unscheduled) + Sep 1 (Mon, excluded via substitute) → excluded = 1
    // 2026: Aug 31 (Mon, excluded directly) → excluded = 1
    expect($kpi2025->metadata->excluded)->toBe(1)
        ->and($kpi2026->metadata->excluded)->toBe(1);
});
```

- [ ] **Step 2: Run tests, expect failure**

Run: `vendor/bin/pest tests/CalculationTest.php --filter="substitut"`
Expected: Several FAILs — no substitute logic exists yet.

- [ ] **Step 3: Add substitute resolution to `src/KPI.php`**

Edit `src/KPI.php`. Replace the line:

```php
$observedDates = $oneOffOccurrences->concat($recurringOccurrences)->pluck('date');
```

with:

```php
$substituteDays = collect(config('kpi.substitute', []))->flip();

$observedDates = $oneOffOccurrences
    ->concat($recurringOccurrences)
    ->map(function (array $occ) use ($schedules, $substituteDays) {
        $date = $occ['date'];

        if ($occ['observes_substitute']
            && $substituteDays->has($date->dayOfWeek)
            && empty($schedules[$date->dayOfWeek])
        ) {
            $i = 0;
            for (; $i < 7; $i++) {
                $date = $date->addDay();
                if (! empty($schedules[$date->dayOfWeek])) {
                    break;
                }
            }
            if ($i === 7) {
                $date = $occ['date'];   // safety fall-back: degenerate schedule
            }
        }

        return $date;
    });
```

- [ ] **Step 4: Run substitute tests, expect pass**

Run: `vendor/bin/pest tests/CalculationTest.php --filter="substitut"`
Expected: All new substitute tests PASS.

Also run the collision test:

Run: `vendor/bin/pest tests/CalculationTest.php --filter="collapses collision"`
Expected: PASS.

- [ ] **Step 5: Run full suite + PHPStan**

Run: `vendor/bin/pest`
Expected: All tests pass — existing 12 calc tests still green (they don't set `observes_substitute`, so substitute branch is skipped), plus all new ones.

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/KPI.php tests/CalculationTest.php
git commit -m "Add observes_substitute resolution with multi-day skip and misconfig guard"
```

---

## Task 14: Update README

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Read the existing Holidays section**

Run: `grep -n "## Holidays" README.md`
Expected: shows line ~123 (the "## Holidays" heading).

- [ ] **Step 2: Replace the Holidays section**

Edit `README.md`. Replace the existing `## Holidays` section (from `## Holidays` through the end of its block, just before `## Tracking movements on a model`) with:

```markdown
## Holidays

The package recognizes two kinds of holiday rows, both contributing to exclusion in `KPI::calculate()`.

### One-off holidays

`Holiday` is a regular Eloquent model:

```php
use Hasyirin\KPI\Models\Holiday;

Holiday::create(['name' => 'New Year', 'date' => '2025-01-01']);

Holiday::query()->range('2025-01-01', '2025-12-31')->get();
```

### Recurring (fixed annual) holidays

For holidays that fall on the same gregorian month/day every year (Labour Day = May 1, Malaysian National Day = Aug 31), use `RecurringHoliday`:

```php
use Hasyirin\KPI\Models\RecurringHoliday;

RecurringHoliday::create([
    'name' => 'Labour Day',
    'month' => 5,
    'day' => 1,
]);
```

The calculator expands these to a concrete date for each year intersecting the calc range. `Feb 29` is silently skipped in non-leap years.

#### Validity windows

`effective_from` and `effective_until` bound when the rule applies. Use them when a holiday was established or retired mid-stream — a 2015 calc should still exclude a holiday that ran 2010–2024, but a 2026 calc should not.

```php
RecurringHoliday::create([
    'name' => 'Old Festival',
    'month' => 6,
    'day' => 15,
    'effective_from' => '2010-01-01',
    'effective_until' => '2024-12-31',
]);
```

Both bounds are nullable; null = unbounded.

### Substitute day (next-working-day observance)

Both `Holiday` and `RecurringHoliday` carry an `observes_substitute` boolean. When `true` AND the row's day-of-week is listed in `config('kpi.substitute')` AND that day is non-working in the active schedule, the calculator observes the holiday on the next working day instead.

Substitute eligibility is configured at the package level because the same weekly schedule can have different substitute policies (Kelantan/Terengganu and Kedah both work Sun-Thu, but Kelantan substitutes a Saturday holiday while Kedah substitutes a Friday one):

```php
// config/kpi.php

// Most of Malaysia (Mon-Fri working, Sat-Sun off):
'substitute' => [Day::SUNDAY->value],

// Kelantan, Terengganu (Sun-Thu working, Fri-Sat off):
'substitute' => [Day::SATURDAY->value],

// Kedah (Sun-Thu working, Fri-Sat off):
'substitute' => [Day::FRIDAY->value],
```

```php
RecurringHoliday::create([
    'name' => 'Labour Day',
    'month' => 5,
    'day' => 1,
    'observes_substitute' => true,
]);
```

#### Multi-day skip and collisions

If the day immediately after the holiday is also non-working, the calculator keeps advancing until it finds a working day (Kedah's `Fri → Sat (off) → Sun` case is the canonical example).

The package does NOT chain substitutes through other holidays — if a substituted holiday lands on a day that is itself a holiday, both observe that same day (the calc loop dedups). If you want explicit chained observance (e.g., the federal Malaysian "next working day if Monday is also a public holiday" rule), add the chained day as its own one-off row.

#### Note on historical calculations

`Movement::saving` stores `period` and `hours` at completion time. Past completed movements are frozen — adding a new holiday row does NOT retroactively change them. This is intentional: a holiday introduced in 2026 should not be assumed to have existed in 2020.

```

- [ ] **Step 3: Verify the README renders sensibly**

Run: `grep -n "Recurring (fixed annual) holidays" README.md`
Expected: shows one match in the Holidays section.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Document RecurringHoliday and observes_substitute in README"
```

---

## Task 15: Final verification — full test suite + PHPStan

**Files:** none (verification only).

- [ ] **Step 1: Run Pest**

Run: `vendor/bin/pest`
Expected: All tests pass. Count should be ~12 (existing CalculationTest) + ~13 new CalculationTest + ~12 RecurringHolidayTest + others = roughly 40+ tests, all green.

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: No errors.

- [ ] **Step 3: Verify git history is clean**

Run: `git log --oneline | head -20`
Expected: 14 commits since the start of this plan, in roughly the order of the tasks above.

- [ ] **Step 4: Report completion to the orchestrator**

No commit at this step. Report task IDs and any deviations from the plan.

---

## Notes for the implementer

- **No CHANGELOG.md edit in this plan.** Per the project convention (separate "Update CHANGELOG" commits), the CHANGELOG entry is drafted in the spec and will be committed separately at release time. Do NOT update CHANGELOG.md as part of this plan.
- **Do NOT push to origin.** Per project convention, all pushes require explicit human authorization.
- **If PHPStan complains about generics on Collection in `occurrencesIn` or `effectiveIn`,** add `@return Collection<int, Carbon>` docblocks. The existing models do not use generics, so match the prevailing style — only add the docblock if PHPStan fails.
- **If a test uses `RecurringHoliday::factory()` and the factory autoload fails,** double-check that `TestCase::setUp()`'s `Factory::guessFactoryNamesUsing` callback is in place (it is, as of the current `tests/TestCase.php`). The factory class name must be `RecurringHolidayFactory` and live in `Hasyirin\KPI\Database\Factories\`.
- **Run `git status` after each commit** to confirm a clean working tree before moving to the next task.
