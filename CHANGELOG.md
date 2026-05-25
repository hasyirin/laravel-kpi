# Changelog

All notable changes to `laravel-kpi` will be documented in this file.

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
2. `php artisan vendor:publish --tag="laravel-kpi-migrations"`
3. `php artisan migrate`
4. (Optional) Re-publish config to see the new `recurring_holidays`, `recurring_holiday`, and `substitute` keys: `php artisan vendor:publish --tag="laravel-kpi-config" --force` — or manually merge them into your existing `config/kpi.php`.
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
2. `php artisan vendor:publish --tag="laravel-kpi-migrations"` (no `--force` — your existing `create_movements_table` migration is untouched; only the new `add_parent_child_to_movements_table` is copied.)
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
2. `php artisan vendor:publish --tag="laravel-kpi-migrations"`
   (no `--force` — your existing `create_movements_table` migration is untouched;
   only the new `add_parent_child_to_movements_table` is copied.)
3. `php artisan migrate` (runs the new `add_parent_child_to_movements_table` migration)
4. Update any `pass(... completesLastMovement: false)` calls to `pass(... supersede: false)`.
5. If you relied on `pass()` auto-closing the previous movement, either pass
   `supersede: true` explicitly or migrate to calling `$movement->complete()`
   when work on a movement is done.
