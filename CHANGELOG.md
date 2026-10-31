# Changelog

All notable changes to `laravel-kpi` will be documented in this file.

## Unreleased

Fixes from a full review of the package. It adds one migration, dispatches events after commit, and makes stale movement instances safe. Read the upgrade guide before you update.

### Fixed

- The publish tags are `kpi-migrations` and `kpi-config`. The `laravel-kpi-*` tags in the README and in earlier upgrade guides published nothing.
- A `config/kpi.php` published before v3.1 no longer breaks `KPI::calculate()` (and so every `complete()`) or the `create_recurring_holidays_table` migration. Laravel merges package config only at the top level, so `kpi.models.recurring_holiday` and `kpi.tables.recurring_holidays` were `null`. Both now fall back to their defaults.
- `add_parent_child_to_movements_table` no longer fails with "duplicate column parent_id" on a table created by the v1.0.0 stub, which already had `parent_id`. Its `down()` now also works on SQLite.
- A completed movement keeps its stored `period` and `hours` when it is saved for another reason (`update(['notes' => ...])`, `touch()`, `restore()`). They are recalculated only when `received_at`, `completed_at` or `status` changes. The new `Movement::recalculate()` applies holiday or schedule changes to past movements on demand.
- `Movement::complete()` re-reads its row under a lock. A stale instance no longer completes a movement a second time, which overwrote `completed_at` and `hours` and fired `Completed` again.
- `Movement::pass()` on a completed movement throws a `LogicException`. Before, it created an open child that nothing could close.
- `complete()` and `pass()` lock the parent row before its children, so concurrent calls no longer deadlock.
- Movements received in the same second are ordered by `id`. `pass()` supersedes the newest one, and `passIfNotCurrent()` compares against the newest one, as `$model->movement` already did.
- `passIfNotCurrent()` matches int-backed enum statuses, and actor keys whose PHP type differs from the stored `actor_id`. Before, both created duplicate movements.
- `Movement::previous()`, `parent()` and `children()` return the configured `kpi.models.movement` class instead of the base `Movement`.
- `Movement` honors `kpi.tables.movements`, and the migrations point the self-referencing foreign keys at that table.
- A subclass's own `$table` wins over `kpi.tables.*` on `Movement`, `Holiday` and `RecurringHoliday`.
- Movements are created, locked and wrapped in a transaction on the movable model's database connection, not the default connection.
- `formatted_interval` no longer drops a unit (a 2h15m movement showed "2 hours 14 minutes").
- `KPI::calculate()` no longer counts the seconds of a mid-day start past the end of the work day, and an empty `Collection` of schedules falls back to `kpi.schedule`.
- `RecurringHoliday::factory()` works outside this repository.
- The default `kpi.formats.datetime` is `d/m/Y h:i A`. `H:i A` printed times such as "13:05 PM".

### Changed

- `Passed` and `Completed` implement `ShouldDispatchAfterCommit`. Laravel dispatches them after the outermost transaction commits and drops them on rollback. A listener that throws can no longer roll back the `pass()` or `complete()` that fired it.
- The trait's `pass()` reloads `$model->movement` before it dispatches `Passed`, so listeners see the new current movement.
- `WorkSchedule` throws an `InvalidArgumentException` when a day does not end after it starts. Before, such a schedule caused a `DivisionByZeroError` or wrong results.
- `formatted_interval` no longer depends on the current date. Durations of 4 weeks or more count a month as 4 weeks.
- New migration `alter_previous_id_hours_and_received_at_on_movements_table`:
  - `previous_id` is `ON DELETE SET NULL`. A hard delete no longer deletes later movements or other tracks. `parent_id` still cascades to children.
  - `hours` is `decimal(12, 4)`. `decimal(8, 4)` overflowed after 9,999 working hours, and MariaDB and Postgres then rejected `complete()`.
  - `received_at` is `NOT NULL DEFAULT CURRENT_TIMESTAMP`. The explicit default stops MySQL and MariaDB with `explicit_defaults_for_timestamp=OFF` from adding `ON UPDATE CURRENT_TIMESTAMP`.

### Upgrade guide

1. `composer update hasyirin/laravel-kpi`
2. `php artisan vendor:publish --tag="kpi-migrations"`
3. Set `received_at` on every movement where it is null. The migration stops with an error while any such row exists. For example: `UPDATE movements SET received_at = created_at WHERE received_at IS NULL`.
4. `php artisan migrate`
5. Check listeners of `Passed` and `Completed` that rely on running inside the transaction of `pass()` or `complete()`.
6. If your published `config/kpi.php` has `'datetime' => 'd/m/Y H:i A'`, change `H` to `h`.

### Internal

- The test suite runs SQLite with foreign key constraints on, and rolls back every migration. It grew from 149 to 187 tests.

## v3.1.2 — typed movement relations - 2026-10-28

Patch release improving the static-analysis types of the `InteractsWithMovement` trait. No runtime behavior changes; no action needed for consumers.

### Fixed

- `InteractsWithMovement::movement()` and `movements()` now declare `MorphOne<Movement, $this>` and `MorphMany<Movement, $this>` return types. Larastan/PHPStan in consuming apps now resolves the related model as `Movement` instead of `Model`, which removes false undefined-property and undefined-method errors on queries such as `$model->movements()->first()`.

### Internal

- Dev dependencies allow Pest 5 (`pestphp/pest`, `pest-plugin-arch`, `pest-plugin-laravel` are now `^3.0||^4.0||^5.0`). Laravel 13 CI runs resolve Pest 5; Laravel 12 runs stay on Pest 4.
- CI uses `actions/checkout@v7`, and the PHPStan workflow also runs when `phpstan-baseline.neon` or `composer.json` changes.
- PHPStan now analyses `InteractsWithMovement` through the test `Task` model, so the `trait.unused` baseline entry is gone.

**Full Changelog**: https://github.com/hasyirin/laravel-kpi/compare/v3.1.1...v3.1.2

## v3.1.1 — accept CarbonImmutable in date parameters - 2026-06-15

Patch release fixing a `TypeError` for apps that configure Carbon to use immutable dates globally (`Date::use(CarbonImmutable::class)`). All public date parameters now type-hint `Carbon\CarbonInterface` instead of the concrete `Illuminate\Support\Carbon`, so both mutable and immutable instances are accepted. Backward-compatible for consumers using the `InteractsWithMovement` trait.

### Fixed

- `Movement::complete()`, `Movement::pass()`, `Movement::passIfNotCurrent()`, and the `InteractsWithMovement` trait equivalents now accept `?CarbonInterface` for their `$at` / `$receivedAt` parameters. Previously, passing a `CarbonImmutable` (e.g. from `now()` when the app sets `Date::use(CarbonImmutable::class)`) threw `Argument #1 ($at) must be of type ?Illuminate\Support\Carbon, Carbon\CarbonImmutable given`.
- `KPI::calculate()`, `Holiday::scopeRange()`, `RecurringHoliday::scopeEffectiveIn()`, and `RecurringHoliday::occurrencesIn()` likewise accept `CarbonInterface|string` for their date-range parameters.
- The `HasMovement` interface and the `@property` docblocks on `Movement` (`received_at`, `completed_at`) and `RecurringHoliday` (`effective_from`, `effective_until`) were updated to `CarbonInterface` to match.

### Note for direct `HasMovement` implementers

If you implement `HasMovement` manually (not via the `InteractsWithMovement` trait) with a concrete `?Carbon` parameter, widen it to `?Carbon\CarbonInterface` to satisfy the updated interface signature. Consumers using the trait need no changes.

### Note for `Movement` subclasses

If you extend `Movement` and override `complete()`, `pass()` or `passIfNotCurrent()` with a `?Illuminate\Support\Carbon` date parameter, widen it to `?Carbon\CarbonInterface`. PHP rejects the narrower override with a fatal error when the class loads.

**Full Changelog**: https://github.com/hasyirin/laravel-kpi/compare/v3.1.0...v3.1.1

## v3.1.0 — fixed/recurring public holidays + substitute-day support - 2026-05-26

Minor release adding annually recurring public holidays and an opt-in next-working-day substitution mechanism. Fully additive — existing consumers see no behavior change until they populate new data or set the new config.

### Added

- `RecurringHoliday` model + `recurring_holidays` table for annually recurring holidays (e.g., Labour Day = May 1, Malaysian National Day = Aug 31). `effective_from` / `effective_until` bound applicability so retired holidays still count for historical KPI calcs but not current ones. Feb 29 is silently skipped in non-leap years.
- `observes_substitute` column on both `holidays` and `recurring_holidays`. When `true` AND the row's day-of-week is listed in `kpi.substitute` AND that day is non-working in the active schedule, the calculator observes the holiday on the next working day. Multi-day skips (Kedah `Fri → Sat (off) → Sun`) supported; no chain-forward through other holidays — collisions collapse onto the same day.
- `kpi.substitute` config key: list of day-of-week values eligible for substitution. Default empty = no substitution. Malaysian state examples documented in `config/kpi.php` and the README.

### Fixed

- `KPI::calculate()` now resolves the holiday model via `config('kpi.models.holiday')` instead of hardcoding `Hasyirin\KPI\Models\Holiday::class`. Consumers who override the holiday model now have it honored at calc time.
- `Holiday::getTable()` now honors `config('kpi.tables.holidays')` (parity with the migration, which already did). `RecurringHoliday::getTable()` follows the same pattern.
- Removed a redundant nullsafe operator on the non-nullable `Movement::received_at` accessor.

### Internal

- Test suite expanded from 124 to 149 tests covering recurring expansion, substitute resolution across the three Malaysian state configurations (Sat-Sun states, Kelantan/Terengganu, Kedah), validity windows, leap-year handling, collision behavior, and the misconfiguration guard.

### Upgrade guide (v3.0 → v3.1)

1. `composer update hasyirin/laravel-kpi`
2. `php artisan vendor:publish --tag="kpi-migrations"`
3. `php artisan migrate`
4. (Optional) Re-publish config to see the new `recurring_holidays`, `recurring_holiday`, and `substitute` keys: `php artisan vendor:publish --tag="kpi-config" --force` — or manually merge them into your existing `config/kpi.php`.
5. Consumers who've overridden `kpi.models.holiday` with a custom subclass must add `observes_substitute` to its `$fillable` and `$casts`.

**Full Changelog**: https://github.com/hasyirin/laravel-kpi/compare/v3.0.0...v3.1.0

## v3.0.0 — drop PHP 8.3 and Laravel 11 support - 2026-05-14

Maintenance release that raises the minimum runtime requirements. No API or behaviour changes; consumers on supported versions can upgrade with no code changes.

### Breaking changes

- Minimum PHP raised from 8.3 to **8.4**.
- Minimum Laravel raised from 11 to **12**. Laravel 12 and 13 are now the supported versions; `illuminate/contracts` is constrained to `^12.0||^13.0`.
- `orchestra/testbench` dev requirement narrowed to `^10.0||^11.0` (testbench 9 dropped alongside Laravel 11).

### Internal

- CI matrix now tests PHP 8.4 and 8.5 against Laravel 12 and 13 (both `prefer-lowest` and `prefer-stable`); PHPStan job bumped to PHP 8.4.

### Upgrade guide (v2 → v3)

1. Ensure your app runs PHP 8.4+ and Laravel 12+.
2. `composer require hasyirin/laravel-kpi:^3.0`

**Full Changelog**: https://github.com/hasyirin/laravel-kpi/compare/v2.0.0...v3.0.0

## v3.0.0 — drop PHP 8.3 and Laravel 11 - 2026-05-15

Maintenance release that raises the minimum runtime requirements. No API or behaviour changes; consumers on supported versions can upgrade with no code changes.

### Breaking changes

- Minimum PHP raised from 8.3 to **8.4**.
- Minimum Laravel raised from 11 to **12**. Laravel 12 and 13 are now the supported versions; `illuminate/contracts` is constrained to `^12.0||^13.0`.
- `orchestra/testbench` dev requirement narrowed to `^10.0||^11.0` (testbench 9 dropped alongside Laravel 11).

### Internal

- CI matrix now tests PHP 8.4 and 8.5 against Laravel 12 and 13 (both `prefer-lowest` and `prefer-stable`); PHPStan job bumped to PHP 8.4.

### Upgrade guide (v2 → v3)

1. Ensure your app runs PHP 8.4+ and Laravel 12+.
2. `composer require hasyirin/laravel-kpi:^3.0`

**Full Changelog**: https://github.com/hasyirin/laravel-kpi/compare/v2.0.0...v3.0.0

## v2.0.0 — multi-track parent/child movements - 2026-05-14

Major release introducing forest-of-movements per resource (multiple concurrent open roots, parent/child trees per root) with receiver-based `pass()` dispatch, explicit `complete()` with cascade, `expects_children` flag, and new `Completed` event. Replaces the v1 single-chain model.

### Breaking changes

- `pass()` no longer auto-completes the prior open movement by default. `completesLastMovement: bool` has been removed. Use the new `supersede: ?bool` parameter (default `null` = inferred) or call `$movement->complete()` explicitly.
- `$file->movement` now returns the latest open movement at *any* depth (previously single-chain). Same Eloquent shape; broader selection.
- `Hasyirin\KPI\Events\Passed::$previous` is now `null` whenever no supersession actually fired (previously always set when the previous flag was on).
- Existing apps must publish and run the new `add_parent_child_to_movements_table` migration to add `parent_id` and `expects_children` columns.

### New features

- Receiver-based dispatch: `$model->pass()` makes a root, `$movement->pass()` makes a child of the receiver.
- New `$movement->complete(?Carbon)` method closes a movement and cascades through open descendants with the same timestamp.
- New `expectsChildren: bool` parameter on both `pass()` receivers (and an `expects_children` column on `movements`) protects planned branch points from auto-supersession.
- New `parent` (BelongsTo) and `children` (HasMany) relations on `Movement`.
- New query scopes on `Movement`: `roots()`, `open()`, `closed()`.
- New `Hasyirin\KPI\Events\Completed` event fires on every movement closure (direct, cascaded, or via supersession).
- Concurrency safety: `pass()` and `complete()` use `lockForUpdate()` inside their `DB::transaction()` blocks.

### Internal improvements

- `Movement::saving` hook now short-circuits when `completed_at` is null, avoiding unnecessary KPI calculation on every save.

### Migration guide (v1 → v2)

1. `composer require hasyirin/laravel-kpi:^2.0`
2. `php artisan vendor:publish --tag="kpi-migrations"` (no `--force` — your existing `create_movements_table` migration is untouched; only the new `add_parent_child_to_movements_table` is copied.)
3. `php artisan migrate` (runs the new `add_parent_child_to_movements_table` migration)
4. Update any `pass(... completesLastMovement: false)` calls to `pass(... supersede: false)`.
5. If you relied on `pass()` auto-closing the previous movement, either pass `supersede: true` explicitly or migrate to calling `$movement->complete()` when work on a movement is done.

**Full Changelog**: https://github.com/hasyirin/laravel-kpi/compare/v1.1.0...v2.0.0

## v1.1.0 - 2026-05-14

### Changes

- Hardened movement tracking and KPI calculation (`db60f7d`)
- Rewrote README (`db60f7d`)
- Removed `parent_id` column, relationships, and fillable entry from `Movement` (`885cc91`) — note: reintroduced with new semantics in v2.0.0
- Dependency updates (dependabot, checkout, composer-install, etc.)

**Full Changelog**: https://github.com/hasyirin/laravel-kpi/compare/v1.0.0...v1.1.0

## v1.0.0 — Laravel 11/12/13 support, PHP 8.3+ - 2026-05-14

Initial release.

- Laravel 11, 12, and 13 support
- PHP 8.3+

## v2.0.0

### Breaking changes

- `pass()` no longer auto-completes the prior open movement by default.
  `completesLastMovement: bool` parameter has been removed. Use the new
  `supersede: ?bool` parameter (default `null` = inferred) or call
  `$movement->complete()` explicitly.
- `$file->movement` now returns the latest open movement at *any* depth
  (previously it was scoped to single-chain semantics). Same Eloquent shape;
  broader selection.
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
- New `Hasyirin\KPI\Events\Completed` event fires on every movement closure
  (direct, cascaded, or via supersession).
- Concurrency safety: `pass()` and `complete()` use `lockForUpdate()` inside
  their `DB::transaction()` blocks.

### Internal improvements

- `Movement::saving` hook now short-circuits when `completed_at` is null,
  avoiding unnecessary KPI calculation on every save.

### Migration guide (v1 → v2)

1. `composer require hasyirin/laravel-kpi:^2.0`
2. `php artisan vendor:publish --tag="kpi-migrations"`
   (no `--force` — your existing `create_movements_table` migration is untouched;
   only the new `add_parent_child_to_movements_table` is copied.)
3. `php artisan migrate` (runs the new `add_parent_child_to_movements_table` migration)
4. Update any `pass(... completesLastMovement: false)` calls to `pass(... supersede: false)`.
5. If you relied on `pass()` auto-closing the previous movement, either pass
   `supersede: true` explicitly or migrate to calling `$movement->complete()`
   when work on a movement is done.
