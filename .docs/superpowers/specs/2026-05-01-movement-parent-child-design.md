# v2.0 — Multi-track movements with parent/child relationships

**Status:** Spec, not yet implemented.
**Target version:** 2.0.0 (major bump, breaking changes).

## Problem

Today, `laravel-kpi` supports exactly one open movement per resource. `pass()` always supersedes the prior open movement, producing a strict linear chain. This is too narrow for workflows where:

1. A resource genuinely has **multiple concurrent statuses** at once (e.g. an order is `payment: pending` *and* `fulfillment: packing`).
2. A movement spawns **sub-movements** that run while the parent stays open (e.g. an agency receives a file, then hands sub-tasks to multiple staff in parallel).

These two needs combine into: a resource holds a *forest* of movements, where each tree is a "track" and each node can have multiple concurrent children.

## Worked example

```
1. Alice → agency                      (root #1, open)
2. Agency → Bob (sub-task)             (child #2 of #1, open)
3. Agency → Dave (parallel sub-task)   (child #3 of #1, open, sibling of #2)
4. Alice → Charlie review (parallel)   (root #4, open)
5. Bob → assistant (sub-sub-task)      (child #5 of #2, open)
```

Five movements open simultaneously on one file. Two roots (#1, #4), one with two open children (#2, #3), one of which has a child of its own (#5).

## Design choices (resolved during brainstorming)

- **Tree per track, multiple roots per resource.** Yes (Q1, Q2 = A).
- **Parent stays open and accrues time inclusively when a child exists.** Parent's `hours` covers full duration, including time children were active. Parent auto-completes only via explicit action (Q3 = A).
- **API dispatch by receiver.** `$model->pass()` makes a root; `$movement->pass()` makes a child of the receiver.
- **Auto-supersession is conditional and overridable.** Default: close the previous root/sibling iff it's a leaf (no children of its own). Override via `supersede: ?bool` parameter (Q-flag).
- **Explicit completion via `$movement->complete()`.** Cascades through open descendants (Q-C = II).
- **`passIfNotCurrent()` mirrors `pass()`.** On a model: compares against most recent open root. On a movement: compares against most recent open child of receiver (Q-B = iv).

## Data model

### Schema additions

`Movement` table gains one column:

```php
$table->foreignIdFor($this->model, 'parent_id')
    ->index()
    ->nullable()
    ->constrained((new $this->model)->getTable())
    ->cascadeOnDelete();
```

Final shape: `id`, `parent_id` (new, nullable), `previous_id` (existing, nullable), `movable_*`, `sender_*`, `actor_*`, `status`, `period`, `hours`, `notes`, `properties`, `received_at`, `completed_at`, timestamps, soft deletes.

### Field meanings

- **`parent_id`** — null for roots; for children, points to the receiver of `$movement->pass(...)`.
- **`previous_id`** — same-level chain pointer (ordering by `received_at`):
  - For a root: most recent open root before this one on the same resource (or null).
  - For a child: most recent open sibling under the same parent before this one (or null).
  - Set whether or not supersession actually happened.

### Migrations

Two artifacts ship in v2.0:

1. **`create_movements_table.php.stub`** — fresh-install stub, includes `parent_id` inline.
2. **`add_parent_id_to_movements_table.php.stub`** — v1→v2 upgrade migration. Existing users publish and run it after upgrading.

Existing rows from v1 get `parent_id = null` and behave as roots — matches their pre-upgrade single-chain semantics.

## API surface

### `$model->pass(...)` (root)

```php
public function pass(
    BackedEnum|string $status,
    ?Model $sender = null,
    ?Model $actor = null,
    ?Carbon $receivedAt = null,
    ?string $notes = null,
    Collection|array|null $properties = null,
    ?bool $supersede = null,
): Movement;
```

- Always creates a root (`parent_id = null`).
- `previous_id` = most recent open root on the resource (by `received_at`), or null.
- `supersede` semantics:
  - `null` (default): close previous root **iff** it has no *open* children. A root whose children are all closed is treated as a leaf and auto-supersedes.
  - `true`: always close previous root (cascades through any open descendants via `complete()`).
  - `false`: never close; new concurrent root.

### `$movement->pass(...)` (child)

Same signature.

- Always creates a child of the receiver (`parent_id = $movement->id`, `movable_*` copied from receiver).
- `previous_id` = most recent open child of the receiver (by `received_at`), or null.
- `supersede` semantics:
  - `null` (default): close previous open sibling **iff** it has no *open* children of its own.
  - `true`: always close it (cascades through any open descendants).
  - `false`: never close; new concurrent sibling.
- Receiver itself never auto-completes.

### `$movement->complete(?Carbon $at = null): self`

- Sets `completed_at` (defaults to `now()`).
- Cascades: every open descendant gets the same `completed_at`.
- Triggers the `saving` hook on each, computing `period` and `hours`.
- No-op if already completed.
- Wrapped in `DB::transaction()` with `lockForUpdate()` on the open-children query.

### `passIfNotCurrent()` on both receivers

- `$model->passIfNotCurrent(...)` — compares `status` + `actor` against the most recent open **root** (by `received_at`). Returns `false` on match, otherwise calls `pass()`.
- `$movement->passIfNotCurrent(...)` — compares against the most recent open **child** of the receiver (by `received_at`). Returns `false` on match, otherwise calls `pass()`.

### Removed

- `completesLastMovement: bool` parameter — replaced by `supersede: ?bool`.

## Relations and scopes

### `HasMovement` (resource)

```php
// Unchanged — already returns latest open by received_at across any depth.
public function movement(): MorphOne {
    return $this->morphOne(config('kpi.models.movement'), 'movable')
        ->ofMany(['received_at' => 'max'], fn ($q) => $q->whereNull('completed_at'));
}

// Unchanged — full history.
public function movements(): MorphMany {
    return $this->morphMany(config('kpi.models.movement'), 'movable');
}
```

`$file->movement` semantics widen — it can now return a child movement when the deepest active leaf is a child. Same Eloquent shape, broader selection set.

### `Movement`

```php
public function parent(): BelongsTo {
    return $this->belongsTo(self::class, 'parent_id');
}

public function children(): HasMany {
    return $this->hasMany(self::class, 'parent_id');
}

public function previous(): BelongsTo {
    return $this->belongsTo(self::class, 'previous_id');  // unchanged
}

public function movable(): MorphTo {
    return $this->morphTo();  // unchanged
}
```

No dedicated `siblings()` relation — Eloquent doesn't model it cleanly. Use `$movement->parent?->children->reject(fn ($c) => $c->is($movement))` if needed.

### Query scopes on `Movement`

```php
public function scopeOpen(Builder $q): void   { $q->whereNull('completed_at'); }
public function scopeClosed(Builder $q): void { $q->whereNotNull('completed_at'); }
public function scopeRoots(Builder $q): void  { $q->whereNull('parent_id'); }
```

Usage:

```php
$file->movements()->roots()->open()->get();           // open root tracks
$movement->children()->open()->get();                 // active subtasks under this node
$movement->children()->closed()->get();               // historical subtasks
```

### Cascade behavior

`parent_id` foreign key uses `cascadeOnDelete()` — same as `previous_id`. With `SoftDeletes`, regular `delete()` only sets `deleted_at` (no cascade). `forceDelete()` cascades.

### Navigation primitives

- `$movement->movable` — back to the resource.
- `$movement->parent` — up one level.
- `$movement->parent->pass(...)` — create a sibling of `$movement`.
- `$movement->movable->pass(...)` — create a new root on the same resource.

## KPI calculation and completion semantics

### Per-movement calc — unchanged

```php
static::saving(function (self $movement) {
    if (! filled($movement->completed_at)) {
        $movement->period = null;
        $movement->hours  = null;
        return;
    }

    $kpi = $movement->calculate();
    $movement->period = $kpi->period;
    $movement->hours  = $kpi->hours;
});
```

Refactor: short-circuit when `completed_at` is null, avoiding unnecessary `calculate()` on every save.

Each movement is calculated from its own `received_at` → `completed_at`. Inclusive semantics fall out naturally: parent's `hours` covers the full open duration including child intervals. Therefore `parent.hours ≥ Σ children.hours` (≥ rather than = because children may have gaps between them and may overlap each other).

Excluded statuses (`config('kpi.status.<type>.except')`) still skip per-movement.

### `complete()` implementation sketch

```php
public function complete(?Carbon $at = null): self
{
    if (filled($this->completed_at)) {
        return $this;
    }

    $at ??= now();

    return DB::transaction(function () use ($at) {
        $this->children()->open()->lockForUpdate()->get()
             ->each(fn (self $c) => $c->complete($at));

        $this->completed_at = $at;
        $this->save();

        event(new Completed($this));

        return $this;
    });
}
```

- Atomic per subtree close.
- Recursion handles arbitrary depth.
- All descendants get the same `completed_at` (one event in time).
- Each level computes its own `period`/`hours` via the `saving` hook.

### Concurrency: `lockForUpdate()`

Both `pass()` and `complete()` use `lockForUpdate()` on their candidate-row queries inside their respective transactions:

- `pass()` — locks the prior open root (or sibling) before deciding whether to supersede.
- `complete()` — locks open children before cascading.

Without locks, two simultaneous `pass()` calls on the same parent could each read "no open siblings" and both create children with `previous_id = null`, breaking the chain invariant. Same risk for `complete()` racing against an incoming `pass()` adding a new child.

### Aggregation across a tree (out of scope for v2.0)

Computing "tree total hours" or "parent hours excluding children" is consumer responsibility. Add helpers later if there's demand. YAGNI for v2.0.

### Accessors

`formattedPeriod`, `interval`, `formattedInterval`, `formattedReceivedAt` — unchanged. They use `calculate()` lazily on read for incomplete movements.

## Events

### `Passed` (existing, semantics tightened)

```php
class Passed
{
    public function __construct(
        public Movement $current,    // newly created movement
        public ?Movement $previous,  // movement closed by this pass via supersede, or null
    ) {}
}
```

Fires once per `pass()` call (on either receiver).

`$previous` = the movement that was *just closed* by this pass. `null` when:
- No prior open root/sibling existed.
- A prior existed but had children (default rule kept it open).
- `supersede: false` was used.

Consumers wanting the chain pointer regardless of closure should read `$current->previous` (the existing belongsTo).

### `Completed` (new)

```php
class Completed
{
    public function __construct(
        public Movement $movement,
    ) {}
}
```

Fires once per movement closure, regardless of trigger:
- Direct `$movement->complete()`.
- Cascaded close (parent's `complete()` recursing through open children).
- Supersession via `pass()` (which internally calls `complete()` on the prior).

Cascaded closures fire `Completed` for each descendant (deepest first, since recursion completes children before the receiver itself).

## Testing strategy

### Existing test impact

The 87 passing tests cover single-chain semantics. Several `MovementTest` cases need updates because `pass()` no longer auto-completes by default — specifically tests asserting the previous movement is closed after a `pass()` call.

`CalculationTest` is unaffected (tests `KPI::calculate()` directly, which doesn't change).

### New coverage

1. **Tree shape:** `$movement->pass()` creates `parent_id = receiver.id`. `parent`, `children` relations resolve. Scopes filter correctly.
2. **Multiple roots:** Calling `$file->pass()` twice with `supersede: false` creates two roots. Both appear in `$file->movements`. `$file->movement` returns latest open of any depth.
3. **Supersede matrix:** All three values (`null`, `true`, `false`) on both receivers, with leaf and non-leaf priors.
4. **`complete()` cascade:** Closing a parent with multiple levels of open descendants closes all of them with the same `completed_at`. Each gets its own `period`/`hours`.
5. **Events:**
   - `Passed::$previous` is null when supersede skipped, set when supersession fired.
   - `Completed` fires once per closed movement, including cascaded descendants (assert count == subtree size).
6. **End-to-end integration:** Replay the worked example trace and assert the resulting tree shape, `previous_id` chain, and open/closed states.
7. **`previous_id` semantics:** Roots track most-recent-open-root. Children track most-recent-open-sibling. Set whether or not supersession happened.
8. **`passIfNotCurrent` on both receivers:** Returns `false` when status+actor match the relevant scope (root for model, sibling for movement).

Skipping explicit concurrency tests for `lockForUpdate()` — hard to assert reliably in unit tests. Document the lock and trust DB semantics.

## Breaking changes summary (CHANGELOG)

1. `completesLastMovement: bool` parameter on `pass()` removed. Replaced with `supersede: ?bool` (tri-state, smarter default).
2. `$file->pass()` no longer auto-completes prior open movement by default. Use `supersede: true` to preserve old single-chain behavior, or call `$movement->complete()` explicitly.
3. `$file->movement` semantics widened: now returns latest open movement of any depth (could be a root or a child).
4. New migration required: publish and run `add_parent_id_to_movements_table.php.stub`.
5. `Passed::$previous` is now `null` when no supersession happened.

## Non-breaking additions

- `$movement->pass()` — new, creates a child.
- `$movement->complete()` — new, closes a movement and cascades through descendants.
- `$movement->parent`, `$movement->children` — new relations.
- Movement scopes: `roots`, `open`, `closed`.
- `Hasyirin\KPI\Events\Completed` — new event.
