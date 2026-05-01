# Laravel KPI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hasyirin/laravel-kpi.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-kpi)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-kpi/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/hasyirin/laravel-kpi/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-kpi/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/hasyirin/laravel-kpi/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hasyirin/laravel-kpi.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-kpi)

Measure turnaround time against a working schedule, and track status transitions (movements) on any Eloquent model.

Given a start and end timestamp, `laravel-kpi` computes the *effective* working duration — skipping weekends, holidays, and any custom exclude dates — expressed as minutes, hours, and a period ratio against scheduled working minutes. It also ships a lightweight workflow layer: attach the `InteractsWithMovement` trait to a model and you get a chain of status transitions (`pass`, `passIfNotCurrent`), each stamped with the KPI duration it took.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- `ext-bcmath`

## Installation

```bash
composer require hasyirin/laravel-kpi
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="laravel-kpi-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-kpi-config"
```

## Configuration

`config/kpi.php`:

```php
use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;

return [
    'formats' => [
        'datetime' => 'd/m/Y H:i A',
    ],

    'tables' => [
        'movements' => 'movements',
        'holidays'  => 'holidays',
    ],

    'models' => [
        'movement' => Movement::class,
        'holiday'  => Holiday::class,
    ],

    // Weekly work schedule — keyed by Day enum value (Sunday = 0 … Saturday = 6).
    // Days omitted are treated as non-working days.
    'schedule' => [
        Day::MONDAY->value    => ['8:00', '17:00'],
        Day::TUESDAY->value   => ['8:00', '17:00'],
        Day::WEDNESDAY->value => ['8:00', '17:00'],
        Day::THURSDAY->value  => ['8:00', '17:00'],
        Day::FRIDAY->value    => ['8:00', '15:30'],
    ],

    // Status values to exclude from KPI calculation, keyed by movable morph type.
    // e.g. 'App\Models\Task' => ['except' => ['on_hold']].
    'status' => [
        // 'App\Models\Task' => ['except' => ['on_hold']],
    ],
];
```

## Calculating KPI

```php
use Hasyirin\KPI\Facades\KPI;
use Illuminate\Support\Carbon;

$kpi = KPI::calculate(
    start: Carbon::parse('2025-01-01 08:00'),
    end:   Carbon::parse('2025-01-03 15:30'),
);

$kpi->minutes;  // 990.0          — effective working minutes in range
$kpi->hours;    // 16.5           — minutes / 60
$kpi->period;   // 2.0            — sum of (worked / scheduled) per day
$kpi->metadata; // KPIMetadata — counts of scheduled / unscheduled / excluded days
```

### Excluding dates

Holidays in the `holidays` table within the range are always excluded. You can also pass ad-hoc dates:

```php
$kpi = KPI::calculate(
    start: Carbon::parse('2025-01-01 08:00'),
    end:   Carbon::parse('2025-01-03 15:30'),
    excludeDates: [Carbon::parse('2025-01-02')],
);
```

### Overriding the schedule per call

```php
use Hasyirin\KPI\Data\WorkSchedule;
use Hasyirin\KPI\Enums\Day;

$kpi = KPI::calculate(
    start: Carbon::parse('2025-01-06 09:00'),
    end:   Carbon::parse('2025-01-06 15:00'),
    schedules: collect([
        Day::MONDAY->value => WorkSchedule::parse(['9:00', '15:00']),
    ]),
);
```

## Holidays

`Holiday` is a regular Eloquent model with `name` and `date` fillables and a `range()` scope:

```php
use Hasyirin\KPI\Models\Holiday;

Holiday::create(['name' => 'New Year', 'date' => '2025-01-01']);

Holiday::query()->range('2025-01-01', '2025-12-31')->get();
```

## Tracking movements on a model

A resource (any Eloquent model) can hold a *forest* of movements: multiple concurrent
open root tracks, each of which can have a tree of child movements. Receiver-based
dispatch decides whether `pass()` creates a root or a child.

Implement `HasMovement` and apply the trait to any model you want to track:

```php
use Hasyirin\KPI\Concerns\InteractsWithMovement;
use Hasyirin\KPI\Contracts\HasMovement;
use Illuminate\Database\Eloquent\Model;

class Task extends Model implements HasMovement
{
    use InteractsWithMovement;
}
```

### `$model->pass()` — create a root

```php
$task = Task::create([...]);

$movement = $task->pass(
    status:           'open',
    sender:           $system,        // who triggered the transition (optional)
    actor:            $user,          // who is now responsible (optional)
    receivedAt:       now(),          // defaults to now()
    notes:            'Created via API',
    properties:       ['source' => 'web'],
    supersede:        null,           // see "supersede" below
    expectsChildren:  false,          // see "expectsChildren" below
);
```

`BackedEnum` statuses are accepted:

```php
enum TaskStatus: string {
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Closed     = 'closed';
}

$task->pass(TaskStatus::InProgress, actor: $user);
```

### `$movement->pass()` — create a child of the receiver

```php
// $root is a Movement returned by $task->pass(...)
$child = $root->pass('with_bob', actor: $bob);
// $child has parent_id = $root->id, inherits movable_id/movable_type from $root.
// $root is NOT auto-completed by this call.
```

To create a sibling of an existing child, call `pass()` on the parent:

```php
$dave = $root->pass('with_dave', actor: $dave_user, supersede: false);
// $dave is a child of $root, sibling of $child.
// supersede: false keeps $child open alongside $dave.
```

To start a new concurrent root from a deeply nested context, go through `movable`:

```php
$deep_child->movable->pass('audit', actor: $auditor);
```

> **Refreshing relations:** `$movement->pass(...)` does not auto-reload the receiver's
> `children` relation cache. If you need fresh state after appending children, call
> `$root->load('children')` or `$root->refresh()`. The trait's `$model->pass()` does
> reload `$model->movement` for backwards compatibility with v1 single-chain callers.

### `$movement->complete()` — close a movement

`pass()` no longer auto-completes prior movements by default (see `supersede` below).
Use `complete()` when you're done with a movement:

```php
$movement->complete();             // closed at now()
$movement->complete($at);          // closed at a specific Carbon instance
```

If the movement has open descendants, they are cascade-closed with the same
`completed_at` timestamp. Each level computes its own `period`/`hours` via the
`saving` hook.

`complete()` is wrapped in a database transaction with `lockForUpdate()` on the
open-children query, making concurrent `pass()`/`complete()` interleaving safe.

> **Concurrency note:** `pass()` and `complete()` use `lockForUpdate()` to serialize
> operations on the same "previous" row, but a concurrent INSERT of a new sibling/root
> by a third transaction is not blocked. Two simultaneous `pass()` calls at the same
> level may both pick a "previous" that's about to be invalidated by the other,
> producing a stale `previous_id` chain. If you require strict ordering, serialize at
> the application layer (queue worker, lock-based job, etc.).

### `supersede` semantics

When you call `$model->pass()` or `$movement->pass()`, the same-level *previous*
movement (most recent open root for `$model->pass()`, most recent open sibling for
`$movement->pass()`) may be auto-closed. The `supersede` parameter controls this:

| Value          | Behavior                                                                                    |
| -------------- | ------------------------------------------------------------------------------------------- |
| `null` *(default)* | Close previous **iff** it has no open children **and** `expects_children == false`.     |
| `true`         | Always close previous — cascades through any open descendants.                              |
| `false`        | Never close previous — the new movement runs concurrently.                                  |

The default is convenient for sequential single-track workflows (a leaf root
naturally yields to its successor) while staying safe for trees (a parent with
open children, or a planned branch point with `expects_children`, is preserved).

### `expectsChildren`

Marks a movement as a planned branch point. While `expects_children = true`, the
movement is protected from auto-supersession even when childless. The flag is
**sticky** — set once at creation, persists for the row's lifetime.

```php
$agency = $file->pass('at_agency', actor: $agency_user, expectsChildren: true);
// Later, even before $agency has any children:
$file->pass('review', actor: $reviewer);
// $agency stays open because expects_children = true.
```

### `passIfNotCurrent()`

Only creates a new movement if the current one (most recent open root for
`$model`, most recent open child for `$movement`) doesn't already match the given
status **and** actor:

```php
$movement = $task->passIfNotCurrent(TaskStatus::Open, actor: $user);
// Movement instance on change, false on no-op.
```

### Reading movements and the tree

```php
$task->movement;                              // latest open movement of any depth (root or child), or null
$task->movements;                             // MorphMany — full history, all depths
$task->movements()->roots()->open()->get();   // open root tracks
$task->movements()->roots()->closed()->get(); // historical roots
```

On a `Movement`:

```php
$movement->parent;          // BelongsTo Movement (null for roots)
$movement->children;        // HasMany Movement
$movement->children()->open()->get();
$movement->children()->closed()->get();
$movement->previous;        // BelongsTo Movement — same-level chain pointer
$movement->movable;         // MorphTo — back to the resource
```

Query scopes on `Movement`:

| Scope      | Filter                          |
| ---------- | ------------------------------- |
| `roots()`  | `whereNull('parent_id')`        |
| `open()`   | `whereNull('completed_at')`     |
| `closed()` | `whereNotNull('completed_at')`  |

### Computed attributes on `Movement`

`period` and `hours` are stored on save **only when `completed_at` is set**.
Incomplete movements have `null` for both; the on-the-fly accessors below
fall back to live calculation.

| Attribute               | Description                                                                                       |
| ----------------------- | ------------------------------------------------------------------------------------------------- |
| `period`                | Stored on save. Ratio of worked time to scheduled time on a completed movement.                  |
| `hours`                 | Stored on save. Worked time in hours.                                                            |
| `interval`              | Accessor. `hours * 3600` in seconds.                                                             |
| `formatted_period`      | Accessor. Falls back to an on-the-fly calculation for incomplete movements.                      |
| `formatted_interval`    | Accessor. Human-readable duration (e.g. `2 hours 15 minutes`).                                   |
| `formatted_received_at` | Accessor. `received_at` formatted via `config('kpi.formats.datetime')`.                          |

For trees: a parent's `hours` is *inclusive* — it covers the full duration the
parent was open, including time its open children were active. So
`parent.hours ≥ Σ children.hours`.

## Events

### `Passed`

Fires after every successful `pass()`:

```php
use Hasyirin\KPI\Events\Passed;

Event::listen(function (Passed $event) {
    $event->current;   // Movement that was just created
    $event->previous;  // Movement that was just CLOSED by this pass via supersede, or null
});
```

`$previous` is `null` when no supersession actually fired — i.e., when the
previous candidate had open children, was marked `expects_children = true`, or
when `supersede: false` was passed. Consumers wanting the chain pointer
regardless of closure should read `$current->previous` (the existing belongsTo).

### `Completed`

Fires once per movement closure, regardless of trigger:

```php
use Hasyirin\KPI\Events\Completed;

Event::listen(function (Completed $event) {
    $event->movement;  // the movement that just closed
});
```

Triggers:
- Direct `$movement->complete()`.
- Cascaded close (parent's `complete()` recursing through open children).
- Supersession via `pass()` (which internally calls `complete()` on the prior).

Cascaded closures fire `Completed` for each descendant — useful for syncing per-movement state to external systems.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hasyirin Fakhriy](https://github.com/hasyirin)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
