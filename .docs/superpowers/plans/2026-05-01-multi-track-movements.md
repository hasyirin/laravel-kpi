# Multi-track parent/child movements (v2.0) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement v2.0 of `hasyirin/laravel-kpi` so a single resource can hold a forest of movements — multiple concurrent open roots and parent/child trees per root — replacing today's single-chain model.

**Architecture:** Adjacency-list tree on the `Movement` table via a new `parent_id` column. Receiver-based dispatch: `$model->pass()` makes a root, `$movement->pass()` makes a child. Auto-supersession is conditional via a new `supersede` parameter (replaces `completesLastMovement`) and a new sticky `expects_children` column that protects planned branch points from being auto-closed. New `$movement->complete()` method handles explicit closure with cascade through descendants. Concurrency safety via `lockForUpdate()` inside `DB::transaction()`. New `Completed` event fires on every movement closure (direct, cascaded, or via supersession).

**Tech Stack:** PHP 8.3+, Laravel 11/12/13, Eloquent ORM (morphTo/MorphMany/BelongsTo/HasMany), Pest 3 with Orchestra Testbench, SQLite in-memory for tests.

**Spec reference:** `.docs/superpowers/specs/2026-05-01-movement-parent-child-design.md`

---

## File Structure

**Modify:**
- `database/migrations/create_movements_table.php.stub` — add `parent_id` (FK self, nullable, index, cascade) and `expects_children` (bool, default false) columns inline.
- `src/Models/Movement.php` — add `parent_id` and `expects_children` to fillable/casts/property docblock; add `parent()` and `children()` relations; add `roots()`, `open()`, `closed()` query scopes; refactor `saving` hook to short-circuit when `completed_at` is null; add `complete()` method with cascade + lockForUpdate; add `pass()` method that creates children.
- `src/Concerns/InteractsWithMovement.php` — refactor `pass()` and `passIfNotCurrent()`: drop `completesLastMovement`, add `?bool $supersede = null` and `bool $expectsChildren = false` parameters; update supersede logic to use `expects_children` flag and "leaf only" rule; use `lockForUpdate()`; tighten `Passed::$previous` to be null when no supersession fired.
- `src/Contracts/HasMovement.php` — update interface signatures to match new `pass()`/`passIfNotCurrent()` parameters.
- `tests/MovementTest.php` — update existing tests that reference `completesLastMovement` to use `supersede`; add new tests for parent/child relations, scopes, `complete()` cascade, `expects_children` flag, multi-track behavior, `Completed` event, and end-to-end integration trace.
- `README.md` — document v2.0 API: `pass()` on movements, `complete()`, `supersede` semantics, `expects_children`, new event.
- `CHANGELOG.md` — v2.0 entry listing breaking changes and new features.

**Create:**
- `database/migrations/add_parent_child_to_movements_table.php.stub` — v1→v2 upgrade migration adding both new columns to existing tables.
- `src/Events/Completed.php` — new event class with one public `Movement $movement` property.

---

## Task 1: Leave `create_movements_table.php.stub` alone

**Files:**
- (No changes.)

The original implementation of this plan modified the create stub to include `parent_id` and `expects_children` inline. That design was reverted because it caused fresh installs to fail: both the create stub and the new `add_parent_child_to_movements_table` stub would publish, and the second migration would error on duplicate columns.

Final design: the create stub stays at v1 schema. The new columns are added by `add_parent_child_to_movements_table` only. Fresh installs run both migrations sequentially; v1 upgraders run only the new one. See Task 2 and the spec's Migrations section.

This task is therefore a no-op. Verify the file is unchanged from v1:

- [ ] **Step 1: Verify create stub is at v1 schema**

Run: `grep -E "parent_id|expects_children" database/migrations/create_movements_table.php.stub`
Expected: NO matches.

---

## Task 2: Create v1→v2 upgrade migration

**Files:**
- Create: `database/migrations/add_parent_child_to_movements_table.php.stub`

- [ ] **Step 1: Write the upgrade migration stub**

Create `database/migrations/add_parent_child_to_movements_table.php.stub`:

```php
<?php

use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected string $table;
    protected string $model;

    public function __construct()
    {
        $this->table = config('kpi.tables.movements');
        $this->model = config('kpi.models.movement');
    }

    public function up(): void
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->foreignIdFor($this->model, 'parent_id')
                ->after('id')
                ->index()
                ->nullable()
                ->constrained((new $this->model)->getTable())
                ->cascadeOnDelete();

            $table->boolean('expects_children')
                ->after('properties')
                ->default(false);
        });
    }

    public function down(): void
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('expects_children');
        });
    }
};
```

- [ ] **Step 2: Register the upgrade stub for publishing**

Open `src/KPIServiceProvider.php` and check the migrations publishing block. Add `add_parent_child_to_movements_table` alongside existing entries.

Run: `grep -n "create_movements_table" src/KPIServiceProvider.php`
Look at how the existing migration is published, then add the new one with the same pattern.

If the provider publishes a directory rather than individual files, no edit needed here — the new stub is auto-included. Verify by running `php artisan vendor:publish --tag=laravel-kpi-migrations --force` in a Testbench shell or by reading `KPIServiceProvider::boot()`.

- [ ] **Step 3: Update `tests/TestCase.php` to load the upgrade migration**

The test environment must mirror real-world fresh installs (which run create then add). Edit `tests/TestCase.php::getEnvironmentSetUp()`:

```php
$migrations = [
    'create_holidays_table',
    'create_movements_table',
    'add_parent_child_to_movements_table',
];

foreach ($migrations as $migration) {
    (include __DIR__.'/../database/migrations/'.$migration.'.php.stub')->up();
}
```

- [ ] **Step 4: Run existing tests**

Run: `vendor/bin/pest`
Expected: All 87 existing tests still pass. The new column defaults (`null` for `parent_id`, `false` for `expects_children`) don't affect existing behavior because no code reads them yet.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/add_parent_child_to_movements_table.php.stub src/KPIServiceProvider.php tests/TestCase.php
git commit -m "feat: add v1→v2 upgrade migration for parent_id and expects_children"
```

---

## Task 3: Add `parent_id` and `expects_children` to Movement fillable/casts/docblock

**Files:**
- Modify: `src/Models/Movement.php:17-67`
- Test: `tests/MovementTest.php`

- [ ] **Step 1: Write a failing test for persistence of new fields**

Add to the bottom of `tests/MovementTest.php`, before any existing trailing brace, in a new section:

```php
// --- New columns: parent_id, expects_children ---

it('persists parent_id when set', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    $movement = new Movement([
        'parent_id' => $root->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'sub',
        'received_at' => now(),
        'properties' => [],
    ]);
    $movement->save();

    expect($movement->fresh()->parent_id)->toBe($root->id);
});

it('persists expects_children when set', function () {
    $task = Task::create(['title' => 'Test']);

    $movement = new Movement([
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'open',
        'received_at' => now(),
        'properties' => [],
        'expects_children' => true,
    ]);
    $movement->save();

    expect($movement->fresh()->expects_children)->toBeTrue();
});

it('defaults expects_children to false', function () {
    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open');

    expect($movement->fresh()->expects_children)->toBeFalse();
});
```

- [ ] **Step 2: Run tests — expect failures**

Run: `vendor/bin/pest --filter="persists parent_id|persists expects_children|defaults expects_children"`
Expected: FAIL — `parent_id` and `expects_children` are not fillable yet, so they'll be silently dropped on save.

- [ ] **Step 3: Update the Movement model**

In `src/Models/Movement.php`, update the property docblock, `$fillable`, and `casts()`:

Replace the docblock (lines 17-33) with:

```php
/**
 * @property int $id
 * @property ?int $parent_id
 * @property int $previous_id
 * @property int $movable_id
 * @property string $movable_type
 * @property int $sender_id
 * @property string $sender_type
 * @property int $actor_id
 * @property string $actor_type
 * @property string $status
 * @property ?float $period
 * @property ?float $hours
 * @property string $notes
 * @property bool $expects_children
 * @property Carbon $received_at
 * @property ?Carbon $completed_at
 * @property float $interval
 */
```

Replace `$fillable` with:

```php
protected $fillable = [
    'parent_id',
    'previous_id',
    'movable_id',
    'movable_type',
    'sender_id',
    'sender_type',
    'actor_id',
    'actor_type',
    'status',
    'period',
    'hours',
    'notes',
    'properties',
    'expects_children',
    'received_at',
    'completed_at',
];
```

Replace `casts()` with:

```php
protected function casts(): array
{
    return [
        'period' => 'float',
        'hours' => 'float',
        'properties' => 'array',
        'expects_children' => 'boolean',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass, including the three new ones.

- [ ] **Step 5: Commit**

```bash
git add src/Models/Movement.php tests/MovementTest.php
git commit -m "feat: add parent_id and expects_children to Movement fillable/casts"
```

---

## Task 4: Add `parent()` and `children()` relations on Movement

**Files:**
- Modify: `src/Models/Movement.php`
- Test: `tests/MovementTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/MovementTest.php`:

```php
// --- parent / children relations ---

it('resolves parent relation', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    $child = new Movement([
        'parent_id' => $root->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'sub',
        'received_at' => now(),
        'properties' => [],
    ]);
    $child->save();

    expect($child->parent)->toBeInstanceOf(Movement::class)
        ->and($child->parent->id)->toBe($root->id);
});

it('returns null parent for roots', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    expect($root->parent)->toBeNull();
});

it('resolves children relation', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    foreach (['a', 'b', 'c'] as $status) {
        $child = new Movement([
            'parent_id' => $root->id,
            'movable_id' => $task->id,
            'movable_type' => Task::class,
            'status' => $status,
            'received_at' => now(),
            'properties' => [],
        ]);
        $child->save();
    }

    expect($root->children)->toHaveCount(3)
        ->and($root->children->pluck('status')->all())->toBe(['a', 'b', 'c']);
});
```

- [ ] **Step 2: Run tests — expect failures**

Run: `vendor/bin/pest --filter="resolves parent|returns null parent|resolves children"`
Expected: FAIL — `parent()` and `children()` methods don't exist on Movement yet.

- [ ] **Step 3: Add relations to Movement**

In `src/Models/Movement.php`, after the existing `previous()` method, add:

```php
public function parent(): BelongsTo
{
    return $this->belongsTo(self::class, 'parent_id');
}

public function children(): HasMany
{
    return $this->hasMany(self::class, 'parent_id');
}
```

Add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the `use` block at the top of the file.

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Models/Movement.php tests/MovementTest.php
git commit -m "feat: add parent and children relations on Movement"
```

---

## Task 5: Add `roots()`, `open()`, `closed()` query scopes on Movement

**Files:**
- Modify: `src/Models/Movement.php`
- Test: `tests/MovementTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/MovementTest.php`:

```php
// --- query scopes ---

it('roots scope filters parentless movements', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    $child = new Movement([
        'parent_id' => $root->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'sub',
        'received_at' => now(),
        'properties' => [],
    ]);
    $child->save();

    expect(Movement::query()->roots()->pluck('id')->all())->toBe([$root->id]);
});

it('open scope filters non-completed movements', function () {
    $task = Task::create(['title' => 'Test']);
    $a = $task->pass('a');
    $b = $task->pass('b');  // closes $a under default supersede

    expect(Movement::query()->open()->pluck('id')->all())->toBe([$b->id]);
});

it('closed scope filters completed movements', function () {
    $task = Task::create(['title' => 'Test']);
    $a = $task->pass('a');
    $b = $task->pass('b');  // closes $a

    expect(Movement::query()->closed()->pluck('id')->all())->toBe([$a->id]);
});
```

- [ ] **Step 2: Run tests — expect failures**

Run: `vendor/bin/pest --filter="roots scope|open scope|closed scope"`
Expected: FAIL — `Call to undefined method Hasyirin\KPI\Models\Movement::roots()` and similar.

- [ ] **Step 3: Add scopes to Movement**

In `src/Models/Movement.php`, add at the bottom of the class (after the accessors):

```php
public function scopeOpen(Builder $query): void
{
    $query->whereNull('completed_at');
}

public function scopeClosed(Builder $query): void
{
    $query->whereNotNull('completed_at');
}

public function scopeRoots(Builder $query): void
{
    $query->whereNull('parent_id');
}
```

Add `use Illuminate\Database\Eloquent\Builder;` to the imports.

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Models/Movement.php tests/MovementTest.php
git commit -m "feat: add roots/open/closed query scopes on Movement"
```

---

## Task 6: Refactor `saving` hook to short-circuit on null `completed_at`

**Files:**
- Modify: `src/Models/Movement.php:70-80`

- [ ] **Step 1: Verify current tests cover both branches**

Run: `vendor/bin/pest --filter="calculates period and hours when completed_at is set|sets period and hours to null when completed_at is empty"`
Expected: PASS — these two tests already cover both code paths and will guard the refactor.

- [ ] **Step 2: Refactor the boot method**

In `src/Models/Movement.php`, replace the `boot()` method (lines 70-80):

```php
protected static function boot(): void
{
    parent::boot();

    static::saving(function (self $movement) {
        if (! filled($movement->completed_at)) {
            $movement->period = null;
            $movement->hours = null;
            return;
        }

        $kpi = $movement->calculate();
        $movement->period = $kpi->period;
        $movement->hours = $kpi->hours;
    });
}
```

- [ ] **Step 3: Run tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass — behavior is identical, just `calculate()` no longer runs unnecessarily on incomplete saves.

- [ ] **Step 4: Commit**

```bash
git add src/Models/Movement.php
git commit -m "refactor: short-circuit saving hook when completed_at is null"
```

---

## Task 7: Create `Completed` event class

**Files:**
- Create: `src/Events/Completed.php`
- Test: `tests/MovementTest.php`

- [ ] **Step 1: Write a failing test**

Add to `tests/MovementTest.php`:

```php
// --- Completed event ---

use Hasyirin\KPI\Events\Completed;

it('Completed event is constructable with a Movement', function () {
    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open');

    $event = new Completed($movement);

    expect($event->movement)->toBe($movement);
});
```

Note: if the `use` directive at the top of the test file doesn't include `Completed`, add it next to the existing `use Hasyirin\KPI\Events\Passed;`.

- [ ] **Step 2: Run test — expect failure**

Run: `vendor/bin/pest --filter="Completed event is constructable"`
Expected: FAIL — `Class "Hasyirin\KPI\Events\Completed" not found`.

- [ ] **Step 3: Create the event class**

Create `src/Events/Completed.php`:

```php
<?php

namespace Hasyirin\KPI\Events;

use Hasyirin\KPI\Models\Movement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Completed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Movement $movement) {}
}
```

- [ ] **Step 4: Run test — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Events/Completed.php tests/MovementTest.php
git commit -m "feat: add Completed event"
```

---

## Task 8: Add `Movement::complete()` method with cascade and lockForUpdate

**Files:**
- Modify: `src/Models/Movement.php`
- Test: `tests/MovementTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/MovementTest.php`:

```php
// --- Movement::complete() ---

it('complete() sets completed_at on a leaf movement', function () {
    Carbon::setTestNow('2025-01-01 09:00:00');

    $task = Task::create(['title' => 'Test']);
    $m = $task->pass('open');

    Carbon::setTestNow('2025-01-01 10:00:00');
    $m->complete();

    expect($m->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 10:00:00');

    Carbon::setTestNow();
});

it('complete() accepts a custom timestamp', function () {
    $task = Task::create(['title' => 'Test']);
    $m = $task->pass('open');

    $at = Carbon::parse('2025-03-15 14:00');
    $m->complete($at);

    expect($m->fresh()->completed_at->toDateTimeString())->toBe('2025-03-15 14:00:00');
});

it('complete() is a no-op when already completed', function () {
    $task = Task::create(['title' => 'Test']);
    $m = $task->pass('open');

    $m->complete(Carbon::parse('2025-01-01 10:00'));
    $first = $m->fresh()->completed_at;

    $m->complete(Carbon::parse('2025-01-01 11:00'));  // should not overwrite
    expect($m->fresh()->completed_at->toDateTimeString())
        ->toBe($first->toDateTimeString());
});

it('complete() cascades through open descendants with same timestamp', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    $child = new Movement([
        'parent_id' => $root->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'sub',
        'received_at' => now(),
        'properties' => [],
    ]);
    $child->save();

    $grandchild = new Movement([
        'parent_id' => $child->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'subsub',
        'received_at' => now(),
        'properties' => [],
    ]);
    $grandchild->save();

    $at = Carbon::parse('2025-01-01 12:00');
    $root->complete($at);

    expect($root->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 12:00:00')
        ->and($child->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 12:00:00')
        ->and($grandchild->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 12:00:00');
});

it('complete() fires Completed event for receiver and each cascaded descendant', function () {
    Event::fake([Completed::class]);

    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    $child = new Movement([
        'parent_id' => $root->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'sub',
        'received_at' => now(),
        'properties' => [],
    ]);
    $child->save();

    $root->complete();

    Event::assertDispatchedTimes(Completed::class, 2);
});
```

- [ ] **Step 2: Run tests — expect failures**

Run: `vendor/bin/pest --filter="complete\\(\\)"`
Expected: FAIL — `Call to undefined method Hasyirin\KPI\Models\Movement::complete()`.

- [ ] **Step 3: Implement `complete()`**

In `src/Models/Movement.php`, add the method after the relations:

```php
public function complete(?\Illuminate\Support\Carbon $at = null): self
{
    if (filled($this->completed_at)) {
        return $this;
    }

    $at ??= now();

    return \Illuminate\Support\Facades\DB::transaction(function () use ($at) {
        $this->children()
            ->whereNull('completed_at')
            ->lockForUpdate()
            ->get()
            ->each(fn (self $child) => $child->complete($at));

        $this->completed_at = $at;
        $this->save();

        \event(new \Hasyirin\KPI\Events\Completed($this));

        return $this;
    });
}
```

(For readability, you can hoist the namespaced classes into the `use` block at the top of the file: `Illuminate\Support\Facades\DB`, `Hasyirin\KPI\Events\Completed`. The inline FQNs are shown above for clarity.)

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass, including the five new `complete()` tests.

- [ ] **Step 5: Commit**

```bash
git add src/Models/Movement.php tests/MovementTest.php
git commit -m "feat: add Movement::complete() with cascade and lockForUpdate"
```

---

## Task 9: Add `Movement::pass()` method (creates child)

**Files:**
- Modify: `src/Models/Movement.php`
- Test: `tests/MovementTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/MovementTest.php`:

```php
// --- Movement::pass() (children) ---

it('Movement::pass() creates a child of the receiver', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');

    $child = $root->pass('with_bob');

    expect($child)->toBeInstanceOf(Movement::class)
        ->and($child->parent_id)->toBe($root->id)
        ->and($child->movable_id)->toBe($task->id)
        ->and($child->movable_type)->toBe(Task::class)
        ->and($child->status)->toBe('with_bob');
});

it('Movement::pass() does not auto-complete the receiver', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');

    $root->pass('with_bob');

    expect($root->fresh()->completed_at)->toBeNull();
});

it('Movement::pass() sets previous_id to most recent open sibling and supersedes it by default', function () {
    Carbon::setTestNow('2025-01-01 09:00');
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');

    Carbon::setTestNow('2025-01-01 10:00');
    $bob = $root->pass('with_bob');

    Carbon::setTestNow('2025-01-01 11:00');
    $devin = $root->pass('with_devin');

    expect($devin->parent_id)->toBe($root->id)
        ->and($devin->previous_id)->toBe($bob->id)
        ->and($bob->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 11:00:00');

    Carbon::setTestNow();
});

it('Movement::pass(supersede: false) keeps previous sibling open', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');
    $bob = $root->pass('with_bob');
    $dave = $root->pass('with_dave', supersede: false);

    expect($bob->fresh()->completed_at)->toBeNull()
        ->and($dave->fresh()->completed_at)->toBeNull()
        ->and($dave->previous_id)->toBe($bob->id);
});

it('Movement::pass() does not supersede a sibling with open children', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');
    $bob = $root->pass('with_bob');
    $bob->pass('drafting');  // give bob an open child

    $dave = $root->pass('with_dave');

    expect($bob->fresh()->completed_at)->toBeNull()
        ->and($dave->previous_id)->toBe($bob->id);
});

it('Movement::pass() does not supersede a sibling with expects_children=true', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');
    $bob = $root->pass('with_bob', expectsChildren: true);

    $dave = $root->pass('with_dave');

    expect($bob->fresh()->completed_at)->toBeNull();
});

it('Movement::pass(supersede: true) cascades close even when sibling has open descendants', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');
    $bob = $root->pass('with_bob');
    $assistant = $bob->pass('drafting');

    $root->pass('with_dave', supersede: true);

    expect($bob->fresh()->completed_at)->not->toBeNull()
        ->and($assistant->fresh()->completed_at)->not->toBeNull();
});

it('Movement::pass() persists expects_children on the new child', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');

    $child = $root->pass('with_bob', expectsChildren: true);

    expect($child->fresh()->expects_children)->toBeTrue();
});

it('Movement::pass() dispatches Passed event with previous null when no supersede happened', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');

    Event::fake([Passed::class]);
    $root->pass('with_bob');  // first child, no previous sibling

    Event::assertDispatched(Passed::class, function (Passed $event) {
        return $event->current->status === 'with_bob' && $event->previous === null;
    });
});

it('Movement::pass() dispatches Passed event with previous when supersede fires', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');
    $bob = $root->pass('with_bob');

    Event::fake([Passed::class]);
    $root->pass('with_devin');  // supersedes bob

    Event::assertDispatched(Passed::class, function (Passed $event) use ($bob) {
        return $event->current->status === 'with_devin' && $event->previous?->id === $bob->id;
    });
});
```

- [ ] **Step 2: Run tests — expect failures**

Run: `vendor/bin/pest --filter="Movement::pass\\(\\)"`
Expected: FAIL — `Call to undefined method Hasyirin\KPI\Models\Movement::pass()`.

- [ ] **Step 3: Implement `Movement::pass()`**

In `src/Models/Movement.php`, add to the imports at the top:

```php
use BackedEnum;
use Hasyirin\KPI\Events\Passed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
```

Then add the `pass()` method (after `complete()`):

```php
public function pass(
    BackedEnum|string $status,
    ?\Illuminate\Database\Eloquent\Model $sender = null,
    ?\Illuminate\Database\Eloquent\Model $actor = null,
    ?Carbon $receivedAt = null,
    ?string $notes = null,
    Collection|array|null $properties = null,
    ?bool $supersede = null,
    bool $expectsChildren = false,
): self {
    $receivedAt ??= now();

    return DB::transaction(function () use (
        $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren
    ) {
        $previous = $this->children()
            ->whereNull('completed_at')
            ->lockForUpdate()
            ->latest('received_at')
            ->first();

        $superseded = null;

        if ($previous && $this->shouldSupersede($previous, $supersede)) {
            $previous->actor_id ??= isset($sender) ? null : $actor?->getKey();
            $previous->actor_type ??= isset($sender) ? null : $actor?->getMorphClass();
            $previous->save();

            $previous->complete($receivedAt);
            $superseded = $previous;
        }

        $movement = new (config('kpi.models.movement'))([
            'parent_id' => $this->getKey(),
            'previous_id' => $previous?->getKey(),
            'sender_id' => $sender?->getKey(),
            'sender_type' => $sender?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'received_at' => $receivedAt,
            'status' => $status instanceof BackedEnum ? $status->value : $status,
            'notes' => $notes,
            'properties' => $properties ?? [],
            'expects_children' => $expectsChildren,
        ]);

        $movement->movable_id = $this->movable_id;
        $movement->movable_type = $this->movable_type;
        $movement->save();

        event(new Passed($movement, $superseded));

        return $movement;
    });
}

protected function shouldSupersede(self $previous, ?bool $supersede): bool
{
    if ($supersede === false) {
        return false;
    }
    if ($supersede === true) {
        return true;
    }
    // null = inferred: close iff (no open children) AND (expects_children == false)
    if ($previous->expects_children) {
        return false;
    }
    return $previous->children()->whereNull('completed_at')->doesntExist();
}
```

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass — the 10 new `Movement::pass()` tests plus all prior tests.

- [ ] **Step 5: Commit**

```bash
git add src/Models/Movement.php tests/MovementTest.php
git commit -m "feat: add Movement::pass() for creating child movements"
```

---

## Task 10: Refactor `InteractsWithMovement::pass()` to use `supersede` and `expectsChildren`

**Files:**
- Modify: `src/Concerns/InteractsWithMovement.php`
- Modify: `tests/MovementTest.php` (rename `completesLastMovement: false` test)

- [ ] **Step 1: Update the existing `completesLastMovement` test to the new param name**

In `tests/MovementTest.php`, find:

```php
it('does not complete previous movement when completesLastMovement is false', function () {
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('open');
    $task->pass('in_progress', completesLastMovement: false);

    $first->refresh();

    expect($first->completed_at)->toBeNull();
});
```

Replace with:

```php
it('does not complete previous root when supersede is false', function () {
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('open');
    $task->pass('in_progress', supersede: false);

    $first->refresh();

    expect($first->completed_at)->toBeNull();
});
```

- [ ] **Step 2: Add new behavior tests for `$model->pass()`**

Append to `tests/MovementTest.php`:

```php
// --- $model->pass() new behavior ---

it('Model::pass() creates a root with parent_id null', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open');

    expect($root->parent_id)->toBeNull();
});

it('Model::pass() does not supersede a root with open children', function () {
    $task = Task::create(['title' => 'Test']);
    $agency = $task->pass('agency');
    $agency->pass('with_bob');

    $charlie = $task->pass('review');

    expect($agency->fresh()->completed_at)->toBeNull()
        ->and($charlie->previous_id)->toBe($agency->id);
});

it('Model::pass() does not supersede a root with expects_children=true', function () {
    $task = Task::create(['title' => 'Test']);
    $agency = $task->pass('agency', expectsChildren: true);

    $charlie = $task->pass('review');

    expect($agency->fresh()->completed_at)->toBeNull();
});

it('Model::pass(supersede: false) keeps both roots open', function () {
    $task = Task::create(['title' => 'Test']);
    $a = $task->pass('agency');
    $b = $task->pass('review', supersede: false);

    expect($a->fresh()->completed_at)->toBeNull()
        ->and($b->fresh()->completed_at)->toBeNull()
        ->and($b->previous_id)->toBe($a->id);
});

it('Model::pass(supersede: true) cascades through descendants', function () {
    $task = Task::create(['title' => 'Test']);
    $agency = $task->pass('agency');
    $bob = $agency->pass('with_bob');

    $task->pass('review', supersede: true);

    expect($agency->fresh()->completed_at)->not->toBeNull()
        ->and($bob->fresh()->completed_at)->not->toBeNull();
});

it('Model::pass() persists expects_children on the new root', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency', expectsChildren: true);

    expect($root->fresh()->expects_children)->toBeTrue();
});

it('Passed::previous is null when no supersede happened on Model::pass()', function () {
    $task = Task::create(['title' => 'Test']);

    Event::fake([Passed::class]);
    $task->pass('open');

    Event::assertDispatched(Passed::class, fn (Passed $e) => $e->previous === null);
});
```

- [ ] **Step 3: Run new tests — expect failures**

Run: `vendor/bin/pest --filter="Model::pass\\(\\)|does not complete previous root|Passed::previous is null"`
Expected: FAIL — current `pass()` uses old `completesLastMovement` logic; doesn't accept `supersede` or `expectsChildren`.

- [ ] **Step 4: Refactor `InteractsWithMovement::pass()`**

Replace `src/Concerns/InteractsWithMovement.php` entirely with:

```php
<?php

namespace Hasyirin\KPI\Concerns;

use BackedEnum;
use Hasyirin\KPI\Contracts\HasMovement;
use Hasyirin\KPI\Events\Passed;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @mixin Model
 * @mixin HasMovement
 */
trait InteractsWithMovement
{
    public function movement(): MorphOne
    {
        return $this->morphOne(config('kpi.models.movement'), 'movable')
            ->ofMany(['received_at' => 'max'], fn ($query) => $query->whereNull('completed_at'));
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(config('kpi.models.movement'), 'movable');
    }

    public function pass(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement {
        $receivedAt ??= now();

        return DB::transaction(function () use (
            $status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren
        ) {
            $previous = $this->movements()
                ->whereNull('parent_id')
                ->whereNull('completed_at')
                ->lockForUpdate()
                ->latest('received_at')
                ->first();

            $superseded = null;

            if ($previous && $this->shouldSupersedeRoot($previous, $supersede)) {
                $previous->actor_id ??= isset($sender) ? null : $actor?->getKey();
                $previous->actor_type ??= isset($sender) ? null : $actor?->getMorphClass();
                $previous->save();

                $previous->complete($receivedAt);
                $superseded = $previous;
            }

            $movement = new (config('kpi.models.movement'))([
                'previous_id' => $previous?->getKey(),
                'sender_id' => $sender?->getKey(),
                'sender_type' => $sender?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'received_at' => $receivedAt,
                'status' => $status instanceof BackedEnum ? $status->value : $status,
                'notes' => $notes,
                'properties' => $properties ?? [],
                'expects_children' => $expectsChildren,
            ]);

            $movement->movable()->associate($this);
            $movement->save();

            event(new Passed($movement, $superseded));

            $this->load('movement');

            return $movement;
        });
    }

    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement|false {
        $latestRoot = $this->movements()
            ->whereNull('parent_id')
            ->whereNull('completed_at')
            ->latest('received_at')
            ->first();

        $statusValue = $status instanceof BackedEnum ? $status->value : $status;
        $sameStatus = $latestRoot?->status === $statusValue;
        $sameActor = $latestRoot?->actor_type === $actor?->getMorphClass()
            && $latestRoot?->actor_id === $actor?->getKey();

        if ($sameStatus && $sameActor) {
            return false;
        }

        return $this->pass($status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren);
    }

    protected function shouldSupersedeRoot(Movement $previous, ?bool $supersede): bool
    {
        if ($supersede === false) return false;
        if ($supersede === true) return true;

        if ($previous->expects_children) return false;
        return $previous->children()->whereNull('completed_at')->doesntExist();
    }
}
```

- [ ] **Step 5: Run all tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass. The "completes the previous movement when passing" test still passes because the previous root is a leaf with `expects_children=false`, which falls through to default-supersede.

- [ ] **Step 6: Commit**

```bash
git add src/Concerns/InteractsWithMovement.php tests/MovementTest.php
git commit -m "feat!: replace completesLastMovement with supersede + expectsChildren"
```

---

## Task 11: Add `Movement::passIfNotCurrent()` for the child case

**Files:**
- Modify: `src/Models/Movement.php`
- Test: `tests/MovementTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/MovementTest.php`:

```php
// --- Movement::passIfNotCurrent() ---

it('Movement::passIfNotCurrent() returns false when latest open child has same status and actor', function () {
    $task = Task::create(['title' => 'Test']);
    $actor = Task::create(['title' => 'Actor']);
    $root = $task->pass('agency');
    $root->pass('with_bob', actor: $actor);

    $result = $root->passIfNotCurrent('with_bob', actor: $actor);

    expect($result)->toBeFalse();
});

it('Movement::passIfNotCurrent() creates a child when status differs from latest sibling', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');
    $root->pass('with_bob');

    $result = $root->passIfNotCurrent('with_dave');

    expect($result)->toBeInstanceOf(Movement::class)
        ->and($result->parent_id)->toBe($root->id)
        ->and($result->status)->toBe('with_dave');
});

it('Movement::passIfNotCurrent() creates a child when no siblings exist', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');

    $result = $root->passIfNotCurrent('with_bob');

    expect($result)->toBeInstanceOf(Movement::class)
        ->and($result->parent_id)->toBe($root->id);
});
```

- [ ] **Step 2: Run tests — expect failures**

Run: `vendor/bin/pest --filter="Movement::passIfNotCurrent"`
Expected: FAIL — method doesn't exist.

- [ ] **Step 3: Implement `Movement::passIfNotCurrent()`**

In `src/Models/Movement.php`, add after `pass()`:

```php
public function passIfNotCurrent(
    BackedEnum|string $status,
    ?\Illuminate\Database\Eloquent\Model $sender = null,
    ?\Illuminate\Database\Eloquent\Model $actor = null,
    ?Carbon $receivedAt = null,
    ?string $notes = null,
    Collection|array|null $properties = null,
    ?bool $supersede = null,
    bool $expectsChildren = false,
): self|false {
    $latest = $this->children()
        ->whereNull('completed_at')
        ->latest('received_at')
        ->first();

    $statusValue = $status instanceof BackedEnum ? $status->value : $status;
    $sameStatus = $latest?->status === $statusValue;
    $sameActor = $latest?->actor_type === $actor?->getMorphClass()
        && $latest?->actor_id === $actor?->getKey();

    if ($sameStatus && $sameActor) {
        return false;
    }

    return $this->pass($status, $sender, $actor, $receivedAt, $notes, $properties, $supersede, $expectsChildren);
}
```

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/pest`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Models/Movement.php tests/MovementTest.php
git commit -m "feat: add Movement::passIfNotCurrent() for child case"
```

---

## Task 12: Update `HasMovement` interface signatures

**Files:**
- Modify: `src/Contracts/HasMovement.php`

- [ ] **Step 1: Read the current contract**

Run: `cat src/Contracts/HasMovement.php`

- [ ] **Step 2: Replace with updated signatures**

Replace `src/Contracts/HasMovement.php` with:

```php
<?php

namespace Hasyirin\KPI\Contracts;

use BackedEnum;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property ?Movement $movement
 * @property Collection $movements
 */
interface HasMovement
{
    public function movement(): MorphOne;

    public function movements(): MorphMany;

    public function pass(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement;

    public function passIfNotCurrent(
        BackedEnum|string $status,
        ?Model $sender = null,
        ?Model $actor = null,
        ?Carbon $receivedAt = null,
        ?string $notes = null,
        Collection|array|null $properties = null,
        ?bool $supersede = null,
        bool $expectsChildren = false,
    ): Movement|false;
}
```

- [ ] **Step 3: Run all tests**

Run: `vendor/bin/pest`
Expected: All tests pass — the trait already matches the new interface from Task 10.

- [ ] **Step 4: Commit**

```bash
git add src/Contracts/HasMovement.php
git commit -m "feat!: update HasMovement contract for v2.0 API"
```

---

## Task 13: Add end-to-end integration test for the worked-example trace

**Files:**
- Modify: `tests/MovementTest.php`

- [ ] **Step 1: Write the integration test**

Append to `tests/MovementTest.php`:

```php
// --- End-to-end: worked example from spec ---

it('handles the multi-track worked example', function () {
    $task = Task::create(['title' => 'File']);
    $agency = Task::create(['title' => 'Agency']);
    $bob = Task::create(['title' => 'Bob']);
    $dave = Task::create(['title' => 'Dave']);
    $charlie = Task::create(['title' => 'Charlie']);
    $alice = Task::create(['title' => 'Alice']);

    // 1. Alice passes the file to the agency (root, expects children since work will branch).
    $m1 = $task->pass('at_agency', sender: $alice, actor: $agency, expectsChildren: true);

    // 2. Agency passes to Bob (child of #1).
    $m2 = $m1->pass('with_bob', actor: $bob);

    // 3. Agency passes to Dave concurrently (sibling of #2, supersede: false).
    $m3 = $m1->pass('with_dave', actor: $dave, supersede: false);

    // 4. Alice opens a parallel review root with Charlie.
    //    #1 has open children, so it stays open without supersede:false.
    $m4 = $task->pass('review', sender: $alice, actor: $charlie);

    // Assertions on the tree shape
    expect($m1->parent_id)->toBeNull()
        ->and($m2->parent_id)->toBe($m1->id)
        ->and($m3->parent_id)->toBe($m1->id)
        ->and($m4->parent_id)->toBeNull();

    // Previous chain
    expect($m2->previous_id)->toBeNull()           // first child of #1
        ->and($m3->previous_id)->toBe($m2->id)     // sibling of bob, supersede:false so bob still open
        ->and($m4->previous_id)->toBe($m1->id);    // most recent open root before review

    // All five (well, four — m1, m2, m3, m4) should still be open
    expect($m1->fresh()->completed_at)->toBeNull()
        ->and($m2->fresh()->completed_at)->toBeNull()
        ->and($m3->fresh()->completed_at)->toBeNull()
        ->and($m4->fresh()->completed_at)->toBeNull();

    // Open roots count
    expect($task->movements()->roots()->open()->count())->toBe(2);

    // Open children of #1
    expect($m1->children()->open()->count())->toBe(2);

    // $task->movement returns the latest open by received_at — most recently passed
    expect($task->fresh()->movement->id)->toBe($m4->id);
});
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/pest --filter="handles the multi-track worked example"`
Expected: PASS — the implementation from prior tasks should support this end-to-end.

- [ ] **Step 3: Commit**

```bash
git add tests/MovementTest.php
git commit -m "test: add end-to-end multi-track worked example"
```

---

## Task 14: Update README to document v2.0 API

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Read current README sections to identify what changes**

Run: `grep -n '^##\|^###' README.md`
The relevant sections are: "Tracking movements on a model", "`pass()`", "`passIfNotCurrent()`", "Reading movements", "Computed attributes on `Movement`", "Events".

- [ ] **Step 2: Rewrite the "Tracking movements on a model" section**

In `README.md`, replace the `pass()` and `passIfNotCurrent()` and Reading movements subsections to document the v2.0 API. The new content should explain:

- `$model->pass(status, ..., supersede: ?bool, expectsChildren: bool)` — creates a root.
- `$movement->pass(...)` — creates a child of the receiver.
- `$movement->complete(?Carbon)` — closes the movement and cascades through descendants.
- `supersede: null|true|false` semantics with the inferred default rule.
- `expectsChildren` flag — sticky protection for planned branch points.
- Worked example from the spec.
- `$model->movement` returns latest open at any depth.
- `$movement->parent`, `$movement->children`, `roots()`, `open()`, `closed()` scopes.

Suggested example block to insert:

```markdown
### Multi-track movements (v2.0)

A resource can hold multiple concurrent open movements organized as a forest.
Roots are created via `$model->pass()`; children are created via `$movement->pass()`.

```php
// Alice hands the file to the agency. The agency is a planned branch point.
$m1 = $file->pass('at_agency', actor: $agency, expectsChildren: true);

// Agency dispatches to two staff in parallel.
$bob  = $m1->pass('with_bob',  actor: $bob);
$dave = $m1->pass('with_dave', actor: $dave, supersede: false);

// Alice opens a parallel review root.
$review = $file->pass('review', actor: $charlie);

// Bob finishes — close his branch only.
$bob->complete();

// Agency wraps everything up.
$m1->complete();  // cascades through any remaining open descendants.
```

#### `supersede`

When you call `$model->pass()` or `$movement->pass()`, the *previous* same-level
movement (most recent open root for `$model`, most recent open sibling for `$movement`)
may be auto-completed. The `supersede` parameter controls this:

| Value      | Behavior                                                                 |
|------------|--------------------------------------------------------------------------|
| `null`     | Default: close previous **iff** it has no open children **and** `expects_children == false`. |
| `true`     | Always close previous (cascades through any descendants).                |
| `false`    | Never close previous — the new movement runs concurrently.               |

#### `expectsChildren`

Marks a movement as a planned branch point. While `expects_children = true`, the
movement is protected from auto-supersession even when childless. The flag is
sticky — set once, persists for the row's lifetime.

#### `complete()`

`$movement->complete(?Carbon $at = null)` closes the movement. If it has open
descendants, they are cascade-closed with the same timestamp. Use this instead
of relying on `pass()` to close prior movements when running multi-track.
```

- [ ] **Step 3: Update the Events section**

Add the `Completed` event documentation:

```markdown
A `Hasyirin\KPI\Events\Completed` event fires after every movement closure
(direct `complete()`, cascaded close, or supersession from `pass()`):

```php
use Hasyirin\KPI\Events\Completed;

Event::listen(function (Completed $event) {
    $event->movement;  // the movement that just closed
});
```
```

- [ ] **Step 4: Update the Computed attributes section**

The existing table is still accurate. Add one line near the top of the section noting that `period`/`hours` are computed *only* when `completed_at` is set, and that for incomplete movements `formattedPeriod`/`interval` accessors fall back to live calculation.

- [ ] **Step 5: Verify README is consistent**

Read the new README end-to-end. Look for stale references to `completesLastMovement` and remove any. Confirm code examples compile mentally.

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "docs: rewrite movement section for v2.0 API"
```

---

## Task 15: Update CHANGELOG.md with v2.0 release notes

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Read current CHANGELOG**

Run: `cat CHANGELOG.md`

- [ ] **Step 2: Prepend the v2.0 entry**

Add at the top of `CHANGELOG.md` (just below the title if there is one):

```markdown
## v2.0.0

### Breaking changes

- `pass()` no longer auto-completes the prior open movement by default.
  `completesLastMovement: bool` parameter has been removed. Use the new
  `supersede: ?bool` parameter (default `null` = inferred) or call
  `$movement->complete()` explicitly.
- `$file->movement` now returns the latest open movement at *any* depth
  (previously it could only return roots in single-chain workflows). Same
  Eloquent shape; broader selection.
- `Hasyirin\KPI\Events\Passed::$previous` is now `null` whenever no
  supersession actually fired (previously always set when the previous flag
  was on).
- Existing apps must publish and run the new
  `add_parent_child_to_movements_table` migration to add `parent_id` and
  `expects_children` columns.

### New features

- Receiver-based dispatch: `$model->pass()` makes a root, `$movement->pass()`
  makes a child of the receiver.
- New `$movement->complete(?Carbon)` method closes a movement and cascades
  through open descendants with the same timestamp.
- New `expectsChildren: bool` parameter on both `pass()` receivers (and an
  `expects_children` column on `movements`) protects planned branch points
  from auto-supersession.
- New `parent` (BelongsTo) and `children` (HasMany) relations on `Movement`.
- New query scopes on `Movement`: `roots()`, `open()`, `closed()`.
- New `Hasyirin\KPI\Events\Completed` event fires on every movement closure.
- Concurrency safety: `pass()` and `complete()` use `lockForUpdate()` inside
  their `DB::transaction()` blocks.

### Internal improvements

- `Movement::saving` hook now short-circuits when `completed_at` is null,
  avoiding unnecessary KPI calculation on every save.

### Migration guide (v1 → v2)

1. `composer require hasyirin/laravel-kpi:^2.0`
2. `php artisan vendor:publish --tag="laravel-kpi-migrations" --force`
3. `php artisan migrate` (runs the new `add_parent_child_to_movements_table` migration)
4. Update any `pass(... completesLastMovement: false)` calls to `pass(... supersede: false)`.
5. If you relied on `pass()` auto-closing the previous movement, either pass
   `supersede: true` explicitly or migrate to calling `$movement->complete()`
   when work on a movement is done.
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add v2.0 changelog entry"
```

---

## Task 16: Final test sweep + composer.json version bump

**Files:**
- Modify: `composer.json` (only if it pins a version field — most packages don't)
- Run: full test suite

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/pest`
Expected: All tests pass, including all new v2.0 coverage.

- [ ] **Step 2: Run static analysis if available**

Run: `vendor/bin/phpstan analyse 2>&1 | tail -30`
Expected: No new errors. If errors mention the new `parent_id`/`expects_children` columns or new methods, fix them inline by updating the property docblock or method return types.

- [ ] **Step 3: Run code style if available**

Run: `vendor/bin/pint --test 2>&1 | tail -20` (or `vendor/bin/php-cs-fixer fix --dry-run --diff` depending on what's installed)
If issues exist, run without `--test` to fix them.

- [ ] **Step 4: Bump version reference if present**

Check if `composer.json` has a `version` field:

Run: `grep '"version"' composer.json`
If present and currently `"1.x"`, update to `"2.0.0"`. If absent (most packages let Packagist derive from tags), skip.

- [ ] **Step 5: Commit any final fixes**

```bash
git add -A
git commit -m "chore: prepare v2.0 release"
```

---

## Self-Review Notes

Verified against the spec:

- ✓ Section "Data model" — Tasks 1, 2, 3 cover schema columns, both migration stubs, fillable/casts.
- ✓ Section "API surface — `$model->pass()`" — Task 10.
- ✓ Section "API surface — `$movement->pass()`" — Task 9.
- ✓ Section "`$movement->complete(...)`" — Task 8.
- ✓ Section "`passIfNotCurrent()` on both receivers" — Tasks 10 (model) and 11 (movement).
- ✓ Section "Removed: `completesLastMovement`" — Task 10.
- ✓ Section "Relations and scopes" — Tasks 4 and 5.
- ✓ Section "KPI calculation — saving refactor" — Task 6.
- ✓ Section "`complete()` cascade + lockForUpdate" — Task 8.
- ✓ Section "`pass()` lockForUpdate" — Tasks 9 and 10 (both transactions use lockForUpdate on the candidate-previous query).
- ✓ Section "Events — `Passed` semantics tightened" — Task 10 sets `Passed::$previous` from a `$superseded` local that's only set when supersession fires.
- ✓ Section "Events — `Completed` new" — Tasks 7 and 8.
- ✓ Section "Testing strategy" — Tests folded into each implementation task plus end-to-end in Task 13.
- ✓ Section "Breaking changes summary (CHANGELOG)" — Task 15.
- ✓ Section "Non-breaking additions" — Task 15.

Type/method consistency check:

- `pass()` parameter order matches across `InteractsWithMovement::pass()`, `Movement::pass()`, `HasMovement::pass()`: `status, sender, actor, receivedAt, notes, properties, supersede, expectsChildren`.
- `complete(?Carbon $at = null)` signature matches across model and trait usage.
- `$shouldSupersede`/`$shouldSupersedeRoot` helper names differ between Movement and trait — intentional (one is on Movement instance for sibling check, one is on trait for root check); both private/protected, no public API impact.
- `Completed::$movement` and `Passed::$current`, `Passed::$previous` types match the event-firing call sites.
- `roots()`, `open()`, `closed()` scope names used consistently across tests and implementation.
