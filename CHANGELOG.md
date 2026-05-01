# Changelog

All notable changes to `laravel-kpi` will be documented in this file.

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
