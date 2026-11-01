# v4.0 — Clean-slate redesign

**Status:** Design, awaiting approval. A Fable review on 2026-10-31 led to the changes marked "(after review)". An architecture review and database tests on 2026-11-01 led to the changes marked "(after tests)".
**Target version:** 4.0.0 (major). No upgrade path from v3.
**Starting point:** the working tree of branch `fix/review-findings` (uncommitted v3 bug fixes, 187 passing tests).

## Why

A full review of v3.1.2 found more than 25 bugs. Most came from three places:

1. **Schema grown for upgrades.** Columns arrived through add/alter migrations, with guards for older installs. Worked time is stored as `hours` with 4 truncated decimals, which loses precision (10 minutes showed as "9 minutes 59 seconds") and can overflow.
2. **A duplicated write path with fragile locking.** `pass()`, `passIfNotCurrent()` and the supersede rules exist twice (trait and model), so every fix had to be made twice. Correctness depends on lock order, and two races remain: `passIfNotCurrent()` checks then acts, and two first passes on a resource can both create a root.
3. **A calculator built on floats, bcmath and truncation.** It counts start seconds wrongly, uses `0.0001` floors, scans excluded dates in quadratic time, and applies the schedule in whatever time zone the input carries.

The v3 fixes on `fix/review-findings` patch these under backward-compatibility limits. v4 drops those limits and rebuilds the three areas. The core model stays: a forest of movements per resource, `pass()` on a model (root) or on a movement (child), the supersede rules, `expects_children`, recurring holidays and substitute days. These were deliberate decisions (see `2026-05-01-movement-parent-child-design.md` and `2026-05-26-fixed-recurring-holidays-design.md`) and the review found no fault in them.

## Goals

1. Every bug from the review is fixed by the design, not by a patch.
2. One obvious way to do each thing, and no word with two meanings.
3. Behavior verified on SQLite, PostgreSQL and MySQL/MariaDB.

## Non-goals

- An upgrade path or migration tooling from v3.
- New features beyond this document: no `next()` relation, no tree aggregation helpers, no recalculate Artisan command.
- SQL Server support. It rejects self-referencing foreign keys with `ON DELETE` actions.
- Changes to the movement forest model, the supersede rules, `expects_children`, or the holiday and substitution rules.

## Anti-goals

These make v4 a failure even if it works:

- Stored KPI values change without an explicit call.
- Formatting or presentation logic comes back into the package.
- A write path that changes the movement structure or `completed_at` without taking the per-resource lock (section 4.3). `recalculate()` changes only stored KPI values and takes no lock.

## Decisions

### Made by the user in this conversation

1. v4 is a clean slate. Assume the package has no prior users; no backward compatibility.
2. Minimum Laravel 13: `illuminate/contracts ^13.0`, Testbench 11, and CI drops Laravel 12. PHP stays `^8.4`.
3. `previous_id` is the movement right before it on the same level, open or closed, "so we can form a link". Keep the name `previous_id` and the `previous()` relation.
4. Rename `Passed::$previous` to `Passed::$superseded`.
5. Direction: rebuild the schema, one write path with a resource lock, a calculator on integer seconds, CI on real databases.
5a. The forest stays: "there's a real need" for parallel and nested movements inside the package. Recurring holidays and substitution stay. Invalid states should be database errors where possible. (2026-11-01)
5b. Approved on 2026-11-01: plain reads after the resource lock; `position` and `level_key` with one unique index; the two `CHECK` constraints; retry on unique violations; no composite foreign key on `parent_id`; document the remaining stale case.
6. Carried over from the v3 fix round:
   - Stored KPI values are recalculated only when `received_at`, `completed_at` or `status` changes; `recalculate()` does it on demand.
   - A hard delete sets `previous_id` to null on later movements (`ON DELETE SET NULL`) and deletes children (`parent_id` `ON DELETE CASCADE`).
   - `received_at` is required (NOT NULL).
   - `pass()` on a completed movement throws `LogicException`.
   - `Passed` and `Completed` dispatch after the outermost transaction commits.

### My calls, stated in chat without objection

- Remove `formatted_period`, `interval`, `formatted_interval`, `formatted_received_at` and the `kpi.formats.datetime` config. Add `Movement::kpi()` and `KPIData::interval()`.
- Remove soft deletes from movements. Keep them on holidays.

### My calls, new in this document (please check)

- Store worked time as integer `seconds` instead of `hours` (section 2).
- Use `dateTime` columns for `received_at` and `completed_at`, holding wall-clock times in the app time zone (section 2).
- Drop the `ext-bcmath` requirement (section 5).
- Add a `kpi.timezone` setting (section 3).
- Both same-level lookups order the level by `position`, so "latest" means last written, and the default `received_at` is read after the lock (section 4.1). (after tests; replaces ordering by `received_at`, which would break `position`)
- A close, or a pass on a movement, first re-reads that movement's row by primary key with `FOR UPDATE` (section 4.3). (after tests)
- `level_key` is a generated column, never written by PHP (section 2). (after tests)
- `movement()` returns the last-written open movement, by `id` (section 4.8). (after tests)
- Callers with their own transaction get the exception and must retry; the package does not refuse to run there (section 4.3). (after tests)
- Rename the fields of `KPIData` and `KPIMetadata` (section 5).
- Move holiday resolution into its own internal class (section 6).
- `Hour` validates with exceptions instead of `assert()`, and rejects `24:00` (section 7).
- The `CHECK` constraints are added on PostgreSQL, MySQL and MariaDB, not on SQLite (section 2). (after tests)
- The resource lock uses the connection that `$movement->movable` would load from (section 4.3). (after review)
- A write on a movement whose resource row is gone throws `LogicException` (section 4.3). (after review; replaces "continue")
- `completed_at` is never earlier than `received_at` (section 4.4).
- `pass()` and `complete()` convert given times to the app time zone (section 4.2). (after review)
- Keep the v3 actor back-fill on supersede, and document it (section 4.5). (after review)
- `period` rounds the sum once instead of truncating each day (section 5). (after review)

## Design

### 1. Requirements

- `php ^8.4`, `illuminate/contracts ^13.0`, `spatie/laravel-package-tools ^1.16`. Remove `ext-bcmath`.
- Dev: `orchestra/testbench ^11.0`, and Pest and Larastan versions that resolve with Laravel 13.
- CI test matrix: PHP 8.4 and 8.5, Laravel 13, prefer-lowest and prefer-stable, Ubuntu and Windows (as today, without Laravel 12), plus the database jobs in section 9.
- Databases: SQLite, MySQL, MariaDB and PostgreSQL. SQL Server is not supported.

### 2. Schema

Ship exactly three migration stubs: `create_movements_table`, `create_holidays_table` and `create_recurring_holidays_table`. Delete `add_parent_child_to_movements_table`, `add_observes_substitute_to_holidays_table` and `alter_previous_id_hours_and_received_at_on_movements_table`, with their guards. Each stub reads its table name from `config('kpi.tables.*', <default>)`, and self-referencing foreign keys point at that same table.

**`movements`**

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | big integer, primary key | |
| `parent_id` | foreign key to `movements.id`, nullable, `ON DELETE CASCADE` | tree parent; null for a root |
| `previous_id` | foreign key to `movements.id`, nullable, `ON DELETE SET NULL` | the movement before it on the same level (4.1) |
| `movable_type`, `movable_id` | morph, required | the resource |
| `level_key` | unsigned big integer, generated: `COALESCE(parent_id, 0)` | the level inside the resource; PHP never writes it |
| `position` | unsigned integer, required | order on the level; written once (4.1) |
| `sender_type`, `sender_id` | morph, nullable | who passed it |
| `actor_type`, `actor_id` | morph, nullable | who is responsible |
| `status` | string | a `BackedEnum` value or a string |
| `seconds` | unsigned big integer, nullable | worked seconds; set when completed |
| `period` | decimal(10, 4), nullable | sum of per-day worked/scheduled fractions; set when completed |
| `notes` | text, nullable | |
| `properties` | JSON | model default `[]` |
| `expects_children` | boolean, default false | |
| `received_at` | `dateTime`, required | |
| `completed_at` | `dateTime`, nullable | |
| `created_at`, `updated_at` | `timestamps()` | |

No `deleted_at`. Add an index on `parent_id` for the open-children lookup.

**Constraints that make invalid states database errors** (after tests):

- `UNIQUE (movable_type, movable_id, level_key, position)`. This also serves the same-level lookups. It rejects a duplicate first movement on a level and a fork in the level's chain, so a write from a stale read fails instead of storing a wrong link. `level_key` exists because NULLs do not collide in a unique index, so `parent_id` cannot be used directly.
- The unique index has the explicit name `{table}_level_position_unique`, because the generated name exceeds MySQL's 64 characters. (after build)
- `level_key` is `virtualAs` on MySQL, MariaDB and SQLite, and `storedAs` on PostgreSQL. A stored column is rejected on MySQL 8.4 (error 1215), because `parent_id` has `ON DELETE CASCADE`. PostgreSQL 17 has no virtual columns. The stub chooses by driver with one condition. A generated column cannot be written wrong by a subclass, `$fillable` or a mass update.
- `CHECK (completed_at IS NULL OR completed_at >= received_at)`.
- `CHECK (completed_at IS NOT NULL OR seconds IS NULL)`.

Laravel has no builder for `CHECK`, and SQLite cannot add one with `ALTER TABLE`. So the stub adds both with `DB::statement` on PostgreSQL, MySQL and MariaDB, and skips them on SQLite. Tests for them run only in the database jobs (section 9). On SQLite, the clamp in 4.4 is the only guard, so the suite asserts the clamp directly.

Verified with plain SQL on 2026-11-01. MySQL 8.4 and MariaDB 12.3 used the virtual `level_key`, and PostgreSQL 17 used the stored one. SQLite was checked with both forms for a duplicate root and a parent delete only:

- The worked example of the v2 spec is accepted on MySQL, MariaDB (with a written `level_key`) and PostgreSQL (with a written `level_key`): several open roots, parallel open siblings, nested children.
- A duplicate first movement and a duplicate child are rejected on all of them.
- Deleting a parent with children works on all of them. Deleting a tail, a mid-chain row, a head or all of a resource's movements works on MySQL, and on MariaDB and PostgreSQL with a written `level_key`.

Rejected constraints:

- A unique head rule derived from `previous_id IS NULL` conflicts with `ON DELETE SET NULL`. A delete makes the next row a second head. MariaDB then rejected every delete except the tail (error 1761), and PostgreSQL rejected a mid-chain delete.
- A composite foreign key that forces a child onto its parent's resource needs one more unique index, and the package always copies the resource from the parent.
- One open movement per level or per resource cannot exist, because parallel open movements are the feature.

Why `dateTime`: on MySQL, `TIMESTAMP` ends in 2038. On MySQL and MariaDB with `explicit_defaults_for_timestamp=OFF`, a `NOT NULL` `TIMESTAMP` also silently gets `ON UPDATE CURRENT_TIMESTAMP` (verified on MariaDB 12.3), which would rewrite `received_at` on every update.

Both columns hold wall-clock times in the app time zone (`config('app.timezone')`). That is how Eloquent reads them back (section 4.2).

**`holidays`:** `id`, `name`, `date` (date, indexed), `observes_substitute` (boolean, default false), timestamps, soft deletes.

**`recurring_holidays`:** `id`, `name`, `month`, `day`, `observes_substitute`, `effective_from` (date, nullable), `effective_until` (date, nullable), timestamps, soft deletes. Same as v3.

### 3. Configuration

```php
return [
    'timezone' => null, // the schedule's time zone; null = config('app.timezone')

    'tables' => ['movements' => 'movements', 'holidays' => 'holidays', 'recurring_holidays' => 'recurring_holidays'],

    'models' => ['movement' => Movement::class, 'holiday' => Holiday::class, 'recurring_holiday' => RecurringHoliday::class],

    'schedule' => [/* Day value => ['8:00', '17:00'], as in v3 */],

    'substitute' => [],

    'status' => [/* 'App\Models\Task' => ['except' => ['on_hold', TaskStatus::OnHold]] */],
];
```

- Remove `formats`.
- `timezone` exists because working hours are local time. mpkemaman runs with app time zone `UTC`, so today an 8:00–17:00 schedule is applied in UTC there.
- Every `config()` read in the package passes a default. Laravel merges a published config only at the top level, so a nested key added later is missing from older published files.
- `status.{morph}.except` accepts strings and `BackedEnum` cases. Compare them with the stored status as strings.

### 4. Movements

#### 4.1 Same-level lookups

"Same level" means the children of one parent, or the roots (`parent_id` null) of one resource. Two lookups run on that level. Both order it by `position` descending, with no time filter:

- **Link** (`previous_id`): the latest movement, open or closed.
- **Supersede target**: the latest *open* movement.

They are often the same row, but not always. After `complete()` and then `pass()`, the link points at the closed movement and there is no supersede target.

A new movement takes the link row's `position` plus 1, or 1 if the level is empty. "Latest" means last written, not latest `received_at`. Ordering by `received_at` would break this: after a backdated pass, the link row would not hold the highest `position`, and every later pass on the level would collide. (after tests)

If a tail row is hard-deleted, the next pass reuses its `position`. That is correct, because no row holds it.

A backdated pass (a `receivedAt` earlier than the latest movement on the level) follows the same rules. It links to the latest movement and supersedes the latest open movement. If that movement started later, the clamp in 4.4 gives it zero time. Neither app passes `receivedAt` today. (after review)

When the caller gives no `receivedAt`, read `now()` after the resource lock. If it is read before, two concurrent passes can store their times in the opposite order of their locks. (after review)

In code and docs, call the supersede target "the latest open movement", never "previous".

#### 4.2 One write path

One implementation creates a movement on a level: it runs the lookups, applies the supersede rules, saves the new movement and dispatches `Passed`. It lives on the movement model, so a configured subclass still controls it. These four methods are thin wrappers that only choose the level:

- `InteractsWithMovement::pass()` and `passIfNotCurrent()`: level = roots of `$this`.
- `Movement::pass()` and `passIfNotCurrent()`: level = children of `$this`.

A child copies `movable_type` and `movable_id` from its parent without loading the resource.

Given times (`receivedAt`, and `$at` in `complete()`) are converted to the app time zone before they are stored. Eloquent stores a date as wall-clock time in the date's own zone and reads it back in the app zone. So a `Carbon` in another zone would otherwise shift by the zone offset. (after review)

#### 4.3 Locking

- `pass()`, `passIfNotCurrent()` and `complete()` start a transaction and first lock the resource row (`SELECT ... FOR UPDATE` on the movable's table, by key). Resolve the table and key from `movable_type` through the morph map, without loading the resource model. Use a base query without global scopes, so a soft-deleted resource still locks.
- While the lock is held, no other write on the same resource can interleave. Result: no lock-order rules between package operations and no deadlocks between operations on one resource. `passIfNotCurrent()` and a first pass are atomic when the package owns the transaction, and on PostgreSQL at READ COMMITTED in any transaction. In a caller's transaction on MySQL or MariaDB, see the stale-read rule below.
- After the resource lock, the range lookups (the 4.1 lookups, the open-children query, the descendants query) are plain reads. Reason: on MySQL and MariaDB, a locking range read takes gap locks, and these deadlock across different resources. Verified on MariaDB 12.3 and MySQL 8.4 on 2026-11-01, with two ordinary passes on two resources: locking lookups gave error 1213 in 5 of 5 runs on each server (on MariaDB without the section 2 constraints, on MySQL with them), and plain lookups in 0 of 5. With the section 2 constraints, plain lookups and the primary-key re-read below, linear passes gave 0 of 5 on both, and first passes gave 0 of 5 on MySQL and on MariaDB with a written `level_key`. (after tests)
- A plain read after the lock is fresh when the package owns the transaction, because the read view is created at the first plain read, after the lock. Verified on MariaDB 12.3 with snapshot isolation off and on. PostgreSQL reads fresh data per statement at READ COMMITTED. (after tests)
- Before the package closes a movement, or passes on a movement, it re-reads that row by primary key with `FOR UPDATE`. On an existing row this takes a record lock only, with no gap lock. The package always fills the instance from that row, so a stale instance or an earlier failed attempt leaves nothing behind. If the row is already closed, the package skips the close, so a stale instance never overwrites a newer `completed_at`. The new `Passed` event then has a null `$superseded`, and no `Completed` event fires for that row. If the re-read returns no row (a hard-deleted movement), throw `LogicException` at once. (after tests)
- The trait unsets the cached relation instead of reloading it (4.7).
- Errors and retries (after tests):
  - The package owns the transaction when `transactionLevel()` is 0 at entry on the lock connection and on the movement connection. Then it calls `transaction($callback, 3)`, which retries concurrency errors, inside one small loop of up to 3 attempts that catches `UniqueConstraintViolationException` only, because Laravel does not retry unique violations. When there are two connections, the loop wraps the lock's transaction.
  - Each attempt reads every row again, takes a new `now()` and builds a new movement instance. A rollback drops pending after-commit events, so `Passed` and `Completed` dispatch once. Model events such as `saving` and `created` fire on every attempt, and the README says so for subclass observers.
  - Inside a caller's transaction, the package does not retry. The caller gets `UniqueConstraintViolationException`, or `Illuminate\Database\DeadlockException` (also for MariaDB error 1020, a lock wait timeout and PostgreSQL 40001).
  - `DB::transaction($callback, 3)` retries the deadlock case only. For a unique violation, the caller needs its own `try`/`catch` loop around `DB::transaction()`. The README shows this loop.
  - The caller must never catch either exception from a package call and then commit. After a lock wait timeout, MariaDB keeps the earlier statements of the transaction, and Laravel does not roll back to the savepoint for a concurrency error. So a superseded movement could stay closed with no successor. Verified on MariaDB 12.3 for error 1205. Errors 1020 and 1213 roll back the whole transaction, so they do not cause this.
  - On PostgreSQL, the nested transaction's savepoint keeps the caller's transaction usable after a unique violation (verified on PostgreSQL 17). The caller still must not commit as if the pass succeeded.
  - On PostgreSQL, `lock_timeout` (SQLSTATE 55P03) reaches the caller as a plain `QueryException`, which Laravel does not retry. A lock wait timeout on MySQL and MariaDB reaches the caller as `DeadlockException`. The caller must not catch either and then commit. (after build)
  - Limitation: when the resources are on another connection, a caller's transaction on the movement connection only releases the resource lock before the caller commits. A second request can then miss those writes. With two connections, the caller must not wrap the package call in its own transaction on the movement connection. The README names this. (after build)
- Stale reads inside a caller's transaction. On MySQL and MariaDB, the read view is created by the first plain read of *any* table in the transaction. So if the caller's own transaction read any table before calling the package, the plain lookups can be stale. Verified on MariaDB 12.3: a read of an unrelated table made a later read of movements stale, even after the resource lock. (after tests)
  - A stale link or `position` is rejected by the unique index. Verified with the first-movement and open-tail cases on MySQL 8.4 and MariaDB 12.3 (error 1062, or 1020 with snapshot isolation on), and the closed-tail case on MariaDB 12.3.
  - Two cases stay silent, and no constraint catches them. The open-children and descendants queries can miss a new child, so a supersede or cascade can close a parent that has a new open child, or leave that child open. This needs children on the resource. And `passIfNotCurrent()` can compare with a stale row and return `false` without a write. This can happen without children.
  - The README says: on MySQL and MariaDB, inside your own transaction, make the package call the first statement that reads any table. Otherwise, pass `supersede` explicitly and do not rely on `passIfNotCurrent()`.
- Connections. Movement queries run on the movement model's connection (via the relations, as in the v3 fixes). The resource lock runs where the resource row lives: (after review)
  - From the trait (`$model->pass()`): the resource instance's connection.
  - From a movement (`$movement->pass()`, `complete()`): the connection that `$movement->movable` would load from. That is the resource class's `$connection`, or the movement's connection when the class sets none (`MorphTo::createModelByType()`). This also covers a movement loaded with `Movement::find()`.
  - Not supported: movements on their own connection while the resource class sets no `$connection`. Laravel's `morphTo` cannot load the resource there either. The README says so.
  - When the two connections differ, the lock's transaction wraps the movement transaction.
- If the resource row no longer exists (the movable has no foreign key), throw `LogicException`. So every write holds the lock. (after review)
- Inside a caller's transaction, the caller must not write movement rows before it calls the package. Otherwise it can deadlock with another request that holds the resource lock and needs those rows. The README says so. (after review)
- `recalculate()` updates one row's stored values and does not take the resource lock.

#### 4.4 `complete()`

- `$at` defaults to `now()`, read after the lock. A given `$at` is converted to the app time zone (4.2).
- If the locked row is already completed, fill the instance from that row (no second read) and return it without an event, even when the instance in memory is stale. (after review)
- Otherwise close every open descendant, deepest first, then the movement itself, all at the requested close time `$at`. Each closure stores its KPI values and dispatches `Completed`.
- `completed_at` is never earlier than `received_at`. Each closure stores the later of `$at` and the movement's own `received_at`. This covers `complete($at)` with an early `$at`, a backdated pass (4.1), and a cascade or supersede that reaches a descendant which started after the close time. Such a movement stores zero time.
- The package does not check that a child's `received_at` is after its parent's.

#### 4.5 `pass()` and `passIfNotCurrent()`

- `pass()` on a completed movement throws `LogicException`. Check it inside the lock.
- `passIfNotCurrent()` compares status and actor with the latest open movement on the level (the supersede target). Compare status as a string (a `BackedEnum` becomes its value), and compare the actor by morph class and by key as strings. It runs inside the same lock as the pass it may perform.
- Supersede rules do not change. `supersede: null` closes the target only if it has no open children and `expects_children` is false. `true` always closes it, with its open descendants. `false` never closes it.
- Actor back-fill (v3 behavior, kept): when the pass closes the target and the target has no actor, the target gets the new movement's actor, unless the pass has a sender. It is saved in the same update as `completed_at`. The README documents it. (after review)

#### 4.6 Stored KPI values

- `seconds` and `period` are stored when a movement completes. They are recalculated only when `received_at`, `completed_at` or `status` changes; other saves keep them.
- `recalculate()` recomputes and saves them, for example after a forgotten holiday is added.
- `kpi(?CarbonInterface $at = null): KPIData` returns the stored values for a closed movement, with empty metadata, and ignores `$at`. For an open movement, it returns a live calculation from `received_at` to `$at` (default now).
- A status listed in `kpi.status.{morph}.except` stores zero, and `kpi()` returns zero for it, open or closed (as in v3).

#### 4.7 Events

- `Passed(Movement $current, ?Movement $superseded)` and `Completed(Movement $movement)`. Both implement `ShouldDispatchAfterCommit`.
- The trait unsets the cached `movement` relation on the resource before it dispatches `Passed`. The next read of `$model->movement`, in the caller or in a listener after commit, loads it fresh. (after review)

#### 4.8 Relations, scopes and table names

- `previous()`, `parent()` and `children()` use the configured movement class.
- `HasMovement::movement()` returns the last-written open movement of any depth (`ofMany` on `id`). This matches "latest" in 4.1 after a backdated pass, and is the same as today for an app that never passes `receivedAt`. `movements()` returns all of them. (after tests)
- Scopes `open()`, `closed()` and `roots()` do not change.
- `getTable()` on all three models: a subclass `$table` first, then `kpi.tables.*`, then the Laravel default.

### 5. Calculator

```php
KPI::calculate(
    CarbonInterface|string $start,
    CarbonInterface|string|null $end = null,   // default now
    Arrayable|array $excludeDates = [],
    Arrayable|array $schedules = [],           // empty = configured schedule
): KPIData
```

1. Convert `$start` and `$end` to `kpi.timezone`. These are instants. Dates (`$excludeDates`, holiday dates, recurring occurrences) are calendar days: take them as `Y-m-d` as given, with no zone conversion. (after review)
2. If `$end` is not after `$start`, return a zero result.
3. Build the set of excluded days as `Y-m-d` keys (section 6).
4. For each calendar day D that overlaps `[start, end)`:
   - D's weekday has no schedule: `unscheduledDays + 1`.
   - D is excluded: `excludedDays + 1`.
   - Otherwise: the work window is D at the schedule start to D at the schedule end, in `kpi.timezone`. L is the window's length in seconds, from the two timestamps, so a DST day has its real length. `scheduledDays + 1` and `scheduledSeconds + L`. `overlap = max(0, min(end, windowEnd) - max(start, windowStart))`. Add `overlap` to `seconds` and `overlap / L` to the period sum.
5. `period = round(periodSum, 4)`.

Use integer seconds throughout: take differences of `getTimestamp()` values, because Carbon 3 `diffInSeconds()` returns a float. There is no bcmath, no `0.0001` floor, and no special start-of-day adjustment.

`KPIData` has `int $seconds`, `float $period` and `KPIMetadata $metadata`, with `minutes(): float`, `hours(): float`, `interval(): CarbonInterval` (cascaded), `toArray()` and `equals()`. `equals()` compares all three fields, so the stored `kpi()` of a closed movement (empty metadata) does not equal a fresh calculation. `KPIMetadata` has `scheduledSeconds`, `scheduledDays`, `unscheduledDays` and `excludedDays`.

The existing calculation tests define the expected results, converted to the new units, with one planned change. v3 truncated each day's fraction to 4 decimals and then added them; v4 rounds the sum once. So expected `period` values change in the 4th decimal toward the exact value, for example 0.0555 → 0.0556, 0.111 → 0.1111 and 1.4999 → 1.5 in `tests/ExampleTest.php`. Any other changed value needs a bug from the review as its reason. (after review)

### 6. Holidays

- One internal class returns the excluded-day set for a range and a schedule: one-off holidays, recurring occurrences, substitute days (rules as in the v3.1 spec, including the 7-day look-back), and `$excludeDates`.
- `Holiday::scopeRange` compares the `date` column directly instead of using `whereDate`, so the index on `date` can be used. It compares with date strings (`Y-m-d`) of the range bounds, taken in `kpi.timezone`. A bound with a time would drop a holiday on the first day, because SQLite compares the strings and MySQL compares a date with a date-time. (after review)
- `scopeRange` uses `date < end date + 1 day`, because the `date` cast stores a time on SQLite, so `date <= end date` would drop a holiday on the last day. (after build)
- `RecurringHoliday::factory()` works outside this repository.

### 7. Validation

- `Hour` throws `InvalidArgumentException` when the hour is outside 0–23 or the minute is outside 0–59. This replaces `assert()`, which does nothing when `zend.assertions` is `-1`. So `24:00` is rejected; v3 accepted it in production, and neither app uses it.
- `WorkSchedule` throws `InvalidArgumentException` when the end is not after the start.

### 8. Public API changes from v3

| Kind | Item |
| --- | --- |
| Removed | `formatted_period`, `interval`, `formatted_interval` and `formatted_received_at` accessors; `kpi.formats`; the `hours` column; the `KPIData::$minutes` and `$hours` properties; soft deletes on `Movement`; the add/alter migrations; `ext-bcmath`; Laravel 12 |
| Renamed | `Passed::$previous` to `$superseded`; the `KPIMetadata` fields |
| Added | `Movement::recalculate()`, `Movement::kpi()`, `KPIData::$seconds`, `KPIData::minutes()`, `hours()` and `interval()`, `kpi.timezone`, the `seconds`, `level_key` and `position` columns, the unique index and `CHECK` constraints |
| Changed | `previous_id` links to the last-written movement on the level even when it is closed; writes lock the resource row; the package retries deadlocks and unique violations when it owns the transaction, and callers inside their own transaction receive the exception; `pass()` or `complete()` on a hard-deleted movement throws; `movement()` orders by `id`; given times are converted to the app time zone; events dispatch after commit; `pass()` on a completed movement throws; a write whose resource row is gone throws; `period` rounds instead of truncating; `WorkSchedule` and `Hour` throw on invalid input |

### 9. Testing and CI

- Test-first, as in the v3 fix round. Carry over the 187 tests from `fix/review-findings` and rewrite them for the v4 API. Drop the tests that only cover upgrade paths.
- `TestCase` keeps foreign keys on for SQLite. `lockForUpdate()` compiles to nothing on SQLite, so only the PostgreSQL and MySQL jobs and the race check cover locking. The `CHECK` constraints exist only on those databases too. The unique index works on SQLite, so the suite tests it everywhere. (after tests)
- CI adds jobs that run the Pest suite on PostgreSQL and MySQL (GitHub Actions service containers), selected by environment variables. The SQLite jobs stay.
- Before release, run a two-process race check against real PostgreSQL, MySQL and MariaDB. Cover complete versus pass, a pass on a stale completed movement, two first passes on one resource, two first passes on two different new resources and two ordinary passes on two resources (expect no deadlock), two `passIfNotCurrent()` calls, a stale write inside a caller's transaction (expect a unique violation, or 1020 on MariaDB with snapshot isolation on, not a wrong link), a retry after a unique violation (expect one row and one `Passed`), a retry with two connections, a caller that gets a lock wait timeout, a backdated pass followed by a normal pass (expect no collision), and hard deletes of a head, a mid-chain row and a parent. Base it on the harness from the v3 fix round and the SQL checks from the reviews. The 2026-11-01 results came from plain SQL, so this check must run the package code on all three servers. Running it in CI is optional.
- PHPStan (current level 5) and Pint stay green.

### 10. Documentation

- Rewrite the README for v4: publish tags `kpi-config` and `kpi-migrations`, `kpi()`, `recalculate()`, `kpi.timezone`, stored times in the app time zone, the resource lock, the actor back-fill, and events after commit. For callers that use their own transaction: the retry loop for `DeadlockException` and `UniqueConstraintViolationException`, never catching them and then committing, not writing movement rows before calling the package, and the stale-read rule in section 4.3. Also say that model events fire on every retry attempt. Replace the calculate example with numbers that a test produces.
- `CHANGELOG.md`: one `v4.0.0` section replaces the `Unreleased` section. It lists the changes in section 8 and states that there is no upgrade path from v3.

## Left to the builder

- Names of the internal write-path method and the holiday class.
- Exact index definitions.
- Exact dev-dependency constraints and CI database versions.
- The order of the work.

## Notes for the two apps (outside this package)

- Both `MovementDataTable` classes: format `received_at` in the app, and replace `interval`/`formatted_interval` with `kpi()->seconds` and `kpi()->interval()->forHumans(['parts' => 2])`.
- Each app needs a hand-written migration: `hours` becomes `seconds` (times 3600); `received_at` and `completed_at` change type; `deleted_at` goes away on movements; `position` is back-filled per level in `received_at`, `id` order before the unique index is added.
- mbpg: `Cancel` runs statements inside its own transaction before it calls `pass()`, and `Review` also calls `pass()` inside a transaction. Eight other files use both `passIfNotCurrent()` and a transaction; these call sites are not yet traced. Any that read a table first can hit the stale `passIfNotCurrent()` case in 4.3 on MariaDB. Each such site must handle the exceptions the README describes. mbpg uses no children, so the supersede case does not apply.
- mpkemaman: the app time zone is `UTC`, and the app never passes `receivedAt`, so its stored times come from `now()` in UTC. If its working hours are Malaysian time, set `kpi.timezone` to `Asia/Kuala_Lumpur`; that converts those times correctly. It also has its own copy of the calculator in `app/Domain/Shared/KPI/KPI.php`.
- mbpg: the local `.env` sets `APP_TIMEZONE=Asia/Kuala_Lumpur`, so `kpi.timezone` can stay null if production does the same.
- mbpg: `Cancel` passes `CANCELLED` with the note and then a second, final status, and the view reads the note through `movement->previous`. So the view reads the cancelled movement's note, and this is correct. (after review; replaces a wrong guess)
