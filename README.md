# Laravel KPI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hasyirin/laravel-kpi.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-kpi)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-kpi/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/hasyirin/laravel-kpi/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-kpi/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/hasyirin/laravel-kpi/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hasyirin/laravel-kpi.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-kpi)

Measure turnaround time against a working schedule, and track status transitions (movements) on any Eloquent model.

Give `laravel-kpi` a start and an end time. It computes the working time between them. It skips weekends, holidays and any dates you exclude. It returns the result as seconds and as a period (worked time divided by scheduled time, added up per day).

It also has a workflow layer. Add the `InteractsWithMovement` trait to a model. Then you can pass the model through a chain of statuses. Each closed movement stores the KPI time it took.

> **v4 has no upgrade path from v3.** The schema, the config and the public API changed. See [CHANGELOG](CHANGELOG.md).

## Requirements

- PHP 8.4 or later
- Laravel 13
- SQLite, MySQL, MariaDB or PostgreSQL. SQL Server is not supported.

## Installation

```bash
composer require hasyirin/laravel-kpi
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="kpi-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="kpi-config"
```

The migrations make three tables: `movements`, `holidays` and `recurring_holidays`.

## Configuration

`config/kpi.php`:

```php
use Hasyirin\KPI\Enums\Day;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Models\RecurringHoliday;

return [
    // The time zone of the schedule. null = config('app.timezone').
    'timezone' => null,

    'tables' => [
        'movements'          => 'movements',
        'holidays'           => 'holidays',
        'recurring_holidays' => 'recurring_holidays',
    ],

    'models' => [
        'movement'          => Movement::class,
        'holiday'           => Holiday::class,
        'recurring_holiday' => RecurringHoliday::class,
    ],

    // Weekly work schedule, keyed by Day enum value (Sunday = 0 ... Saturday = 6).
    // A day that is not listed is a non-working day. Each day must end after it starts.
    'schedule' => [
        Day::MONDAY->value    => ['8:00', '17:00'],
        Day::TUESDAY->value   => ['8:00', '17:00'],
        Day::WEDNESDAY->value => ['8:00', '17:00'],
        Day::THURSDAY->value  => ['8:00', '17:00'],
        Day::FRIDAY->value    => ['8:00', '15:30'],
    ],

    // Day-of-week values whose holidays move to the next working day
    // when observes_substitute = true on the row. Default empty = no substitution.
    // Malaysian state examples:
    //   Sat-Sun states + post-2025 Johor -> [Day::SUNDAY->value]
    //   Kelantan, Terengganu             -> [Day::SATURDAY->value]
    //   Kedah                            -> [Day::FRIDAY->value]
    'substitute' => [],

    // Statuses that store zero KPI time, keyed by the resource morph type.
    // Strings and BackedEnum cases are both allowed.
    'status' => [
        // 'App\Models\Task' => ['except' => ['on_hold', TaskStatus::OnHold]],
    ],
];
```

Hours of work are local time. Set `kpi.timezone` to the time zone of your office. If it is `null`, the package uses `config('app.timezone')`. An app that runs in `UTC` with an 8:00-17:00 schedule needs `kpi.timezone` to get 8:00-17:00 local time.

If you publish the config file, Laravel merges it with the package config at the top level only. Every `config()` read in the package has a default. So a key you did not publish still works.

## Calculating KPI

```php
use Hasyirin\KPI\Facades\KPI;
use Illuminate\Support\Carbon;

$kpi = KPI::calculate(
    start: Carbon::parse('2025-01-01 08:00'),
    end:   Carbon::parse('2025-01-03 15:30'),
);

$kpi->seconds;    // 91800  - working seconds in the range
$kpi->minutes();  // 1530.0
$kpi->hours();    // 25.5
$kpi->period;     // 3.0    - the sum of (worked / scheduled) for each day
$kpi->interval(); // CarbonInterval, for example ->forHumans()
$kpi->metadata;   // KPIMetadata: scheduledSeconds, scheduledDays, unscheduledDays, excludedDays
```

The default schedule has three working days in this range: Wednesday 9 hours, Thursday 9 hours and Friday 7.5 hours. So `period` is 3.0.

`end` defaults to now. If `end` is not after `start`, the result is zero. `period` is the sum of the daily fractions, rounded once to 4 decimals.

`KPIData::equals()` compares `seconds`, `period` and `metadata`. A stored result (see `kpi()` below) has empty metadata, so it does not equal a fresh calculation.

### Excluding dates

The package always excludes the rows of the `holidays` table in the range. You can also pass dates for one call:

```php
$kpi = KPI::calculate(
    start: Carbon::parse('2025-01-01 08:00'),
    end:   Carbon::parse('2025-01-03 15:30'),
    excludeDates: [Carbon::parse('2025-01-02')],
);

$kpi->seconds;   // 59400
$kpi->minutes(); // 990.0
$kpi->hours();   // 16.5
$kpi->period;    // 2.0
```

A date is a calendar day. The package reads it as `Y-m-d` and does not convert its time zone.

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

An empty `schedules` value uses `kpi.schedule`. `Hour` and `WorkSchedule` throw `InvalidArgumentException` for an hour outside 0-23, a minute outside 0-59, or a day that does not end after it starts.

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

A movement stores `seconds` and `period` when it completes. The package recalculates them only when `received_at`, `completed_at` or `status` changes. Other saves (`update(['notes' => ...])`, `touch()`) keep the stored values. A new holiday row does NOT change past movements. This is on purpose: a holiday that starts in 2026 did not exist in 2020.

To apply a holiday that you forgot to record, call `recalculate()` on the movements it affects:

```php
// After you add a forgotten holiday on 2026-05-01
Movement::query()->closed()
    ->where('received_at', '<', '2026-05-02')
    ->where('completed_at', '>=', '2026-05-01')
    ->each(fn (Movement $movement) => $movement->recalculate());
```

`recalculate()` saves one row. It does not take the resource lock.

## Tracking movements on a model

A resource (any Eloquent model) can hold a *forest* of movements. It can have several open root tracks, and each root can have a tree of child movements. The receiver of `pass()` decides if the new movement is a root or a child.

Implement `HasMovement` and add the trait to each model you want to track:

```php
use Hasyirin\KPI\Concerns\InteractsWithMovement;
use Hasyirin\KPI\Contracts\HasMovement;
use Illuminate\Database\Eloquent\Model;

class Task extends Model implements HasMovement
{
    use InteractsWithMovement;
}
```

Always create movements with `pass()`. The package sets `position` and `previous_id` there. `position` is required and is not fillable, so `Movement::create()` cannot set it. Use `pass()`.

### Levels

A *level* is the roots of one resource, or the children of one movement. On each level, the package gives every movement a `position`, one more than the last movement written on that level. A unique index on (resource, level, `position`) rejects two movements with the same position. So a write that used a stale read fails with an error, and the database never stores a wrong chain.

`previous_id` points to the last movement written on the level, open or closed. "Last written" is not "latest `received_at`".

### `$model->pass()` — create a root

```php
$task = Task::create([...]);

$movement = $task->pass(
    status:           'open',
    sender:           $system,        // who triggered the transition (optional)
    actor:            $user,          // who is now responsible (optional)
    receivedAt:       now(),          // defaults to now(), read after the resource lock
    notes:            'Created via API',
    properties:       ['source' => 'web'],
    supersede:        null,           // see "supersede semantics" below
    expectsChildren:  false,          // see "expectsChildren" below
);
```

`BackedEnum` statuses are allowed. The package stores the enum value as a string:

```php
enum TaskStatus: string {
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Closed     = 'closed';
}

$task->pass(TaskStatus::InProgress, actor: $user);
```

The package converts `receivedAt` to the app time zone before it stores it. The `received_at` and `completed_at` columns hold wall-clock times in `config('app.timezone')`.

A `receivedAt` that is earlier than the last movement on the level is allowed. The new movement still links to the last written movement and still supersedes the latest open movement. If that movement started later, it stores zero time.

### `$movement->pass()` — create a child of the receiver

```php
// $root is a Movement returned by $task->pass(...)
$child = $root->pass('with_bob', actor: $bob);
// $child has parent_id = $root->id, and the same movable_id/movable_type as $root.
// $root is NOT closed by this call.
```

`pass()` on a completed movement throws `LogicException`. This is also true when your instance is stale and another request completed the row.

`$movement->pass()` reads the row of the receiver first, like `complete()`. It replaces the attributes of your instance with the stored ones. So unsaved attribute changes on the receiver are lost.

To make a sibling of a child, call `pass()` on the parent:

```php
$dave = $root->pass('with_dave', actor: $dave_user, supersede: false);
// $dave is a child of $root, and a sibling of $child.
// supersede: false keeps $child open next to $dave.
```

To start a new root from a nested movement, go through `movable`:

```php
$deep_child->movable->pass('audit', actor: $auditor);
```

> **Cached relations:** `$movement->pass(...)` does not reload the `children` relation of the receiver. Call `$root->load('children')` or `$root->refresh()` to read it again. `$model->pass()` clears the cached `movement` relation of the resource, so the next `$model->movement` loads fresh data.

### `$movement->complete()` — close a movement

`pass()` does not close earlier movements unless the `supersede` rules say so. Use `complete()` when a movement is done:

```php
$movement->complete();     // closed at now()
$movement->complete($at);  // closed at a given Carbon instance
```

- The package closes open descendants first, deepest first. Then it closes the movement. All use the same close time.
- `completed_at` is never earlier than `received_at`. A movement that started after the close time stores `completed_at = received_at` and zero time.
- Each closure stores its own `seconds` and `period`, and fires `Completed`.
- `complete()` reads the row first. It replaces the attributes of your instance with the stored ones. So **unsaved attribute changes on the instance are lost**. Save them before you call `complete()`.
- If the row is already closed, `complete()` fills the instance from the row and returns it. It fires no event and does not change `completed_at`.
- If the row was hard-deleted, `complete()` throws `LogicException`.

### `supersede` semantics

When you call `$model->pass()` or `$movement->pass()`, the package may close the *latest open movement* on the level. This is the newest open root for `$model->pass()`, or the newest open child for `$movement->pass()`. The `supersede` parameter controls it:

| Value              | Behavior                                                                          |
| ------------------ | --------------------------------------------------------------------------------- |
| `null` *(default)* | Close it only if it has no open children **and** `expects_children` is `false`.   |
| `true`             | Always close it. This also closes its open descendants.                           |
| `false`            | Never close it. The new movement runs next to it.                                 |

The default suits a single track of statuses: a leaf movement ends when the next one starts. It keeps a movement that has open children, or that you marked with `expectsChildren`.

#### Actor back-fill

If the pass closes a movement that has no actor, that movement gets the actor of the new movement. The package does not do this when the pass has a `sender`. It saves the actor in the same update as `completed_at`.

### `expectsChildren`

This flag marks a movement as a planned branch point. While `expects_children` is `true`, `supersede: null` does not close the movement, even if it has no children. You set the flag once, at creation. It stays for the life of the row.

```php
$agency = $file->pass('at_agency', actor: $agency_user, expectsChildren: true);
// Later, before $agency has any children:
$file->pass('review', actor: $reviewer);
// $agency stays open because expects_children = true.
```

### `passIfNotCurrent()`

`passIfNotCurrent()` creates a movement only if the latest open movement on the level does not have the same status **and** the same actor:

```php
$movement = $task->passIfNotCurrent(TaskStatus::Open, actor: $user);
// A Movement on change, false when nothing changed.
```

The package compares the status as a string, and the actor by morph class and by key as a string. The comparison and the write happen under the same lock.

### Reading movements and the tree

```php
$task->movement;                              // the last written open movement, at any depth, or null
$task->movements;                             // MorphMany: the full history
$task->movements()->roots()->open()->get();   // open root tracks
$task->movements()->roots()->closed()->get(); // closed root tracks
```

On a `Movement`:

```php
$movement->parent;          // BelongsTo Movement (null for a root)
$movement->children;        // HasMany Movement
$movement->children()->open()->get();
$movement->children()->closed()->get();
$movement->previous;        // BelongsTo Movement: the last movement written on the same level
$movement->movable;         // MorphTo: the resource
$movement->sender;          // MorphTo
$movement->actor;           // MorphTo
```

Movements have no soft deletes. A hard delete also deletes the children of the movement (`parent_id` cascades). It sets `previous_id` to `null` on the movements that pointed to it. Other movements stay.

Query scopes on `Movement`:

| Scope      | Filter                         |
| ---------- | ------------------------------ |
| `roots()`  | `whereNull('parent_id')`       |
| `open()`   | `whereNull('completed_at')`    |
| `closed()` | `whereNotNull('completed_at')` |

### Stored KPI values

| Column / method | Description                                                                                       |
| --------------- | ------------------------------------------------------------------------------------------------- |
| `seconds`       | Worked seconds. Set when the movement completes. `null` while it is open.                         |
| `period`        | Worked time divided by scheduled time, added up per day. Set when the movement completes.         |
| `kpi()`         | Returns `KPIData`. See below.                                                                     |
| `recalculate()` | Computes `seconds` and `period` again, and saves them.                                            |

```php
$movement->kpi();      // closed: the stored values, with empty metadata. Open: a live calculation up to now.
$movement->kpi($at);   // open: a live calculation up to $at. Closed: $at is ignored.

$movement->kpi()->hours();
$movement->kpi()->interval()->forHumans(['parts' => 2]);
```

A status in `kpi.status.{morph}.except` stores zero, and `kpi()` returns zero for it, open or closed.

A parent's time runs from its own `received_at` to its `completed_at`. It is not the sum of its children.

## Locking, retries and model events

Every `pass()`, `passIfNotCurrent()` and `complete()` runs in a transaction. The first step is `SELECT ... FOR UPDATE` on the row of the resource. While one request holds this lock, no other package call on the same resource can run. So concurrent calls on one resource do not deadlock, and a first pass or `passIfNotCurrent()` cannot run twice at once.

If the resource row does not exist, the package throws `LogicException`.

After the lock, the package reads the level with plain queries. It does this on purpose: locking range reads on MySQL and MariaDB take gap locks, and these deadlock across different resources. Before it closes a movement, the package re-reads that row by primary key with `FOR UPDATE`.

When the package starts the transaction, it retries:

- A concurrency error (deadlock) gets up to 3 attempts from Laravel's `transaction($callback, 3)`.
- A unique violation gets up to 3 attempts from a loop around it. Laravel does not retry unique violations itself.

Each attempt reads every row again, reads a new `now()` and builds a new movement. When an attempt rolls back, Laravel drops its pending events. So `Passed` and `Completed` fire once, after the final commit.

**Model events can fire more than once.** The attempts nest: up to 3 unique-violation attempts wrap up to 3 concurrency attempts. Events like `saving`, `saved`, `creating` and `created` fire on every attempt that reaches the write. An observer on a `Movement` subclass must be safe to run again. Do not use it for side effects outside the database.

Inside a transaction that you started, the package does not retry. See the next section.

### Connections

The package runs movement queries on the connection of the movement model. It runs the resource lock on the connection where the resource row lives:

- `$model->pass()`: the connection of the resource instance.
- `$movement->pass()` and `complete()`: the connection that `$movement->movable` would load from. This is the `$connection` of the resource class, or the connection of the movement when the class sets none.

If the two connections differ, the lock transaction wraps the movement transaction.

Movements on their own connection, while the resource class sets no `$connection`, are not supported. Laravel's `morphTo` cannot load the resource in that case either.

## When you use your own transaction

You can call the package inside `DB::transaction()`. The package then joins your transaction and does not retry. You get the exception and you must handle it.

- `UniqueConstraintViolationException`: a write used a stale read.
- `Illuminate\Database\DeadlockException`: a deadlock, a lock wait timeout on MySQL or MariaDB (1205), a MariaDB snapshot isolation conflict (1020), or a serialization failure on PostgreSQL (40001).

Do not rely on `DB::transaction($callback, 3)` to retry `DeadlockException`. On PostgreSQL, a 40001 error gets code 0 and may not be retried. Use a loop that catches both exceptions:

```php
use Illuminate\Database\DeadlockException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function startWork(Task $task, User $user): Movement
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return DB::transaction(function () use ($task, $user) {
                $movement = $task->pass(TaskStatus::InProgress, actor: $user);

                // your other work here

                return $movement;
            });
        } catch (UniqueConstraintViolationException|DeadlockException $exception) {
            if ($attempt >= 3) {
                throw $exception;
            }
        }
    }
}
```

Rules:

1. **Never catch `DeadlockException` or `UniqueConstraintViolationException` from a package call and then commit.** After a lock wait timeout, MariaDB keeps the earlier statements of the transaction. A superseded movement could stay closed with no successor. On PostgreSQL, a unique violation leaves your transaction usable, but the pass did not happen.
2. **Do not write movement rows before you call the package.** Another request can hold the resource lock and wait for those rows. Both requests then deadlock.
3. **On MySQL and MariaDB, make the package call the first statement that reads any table in the transaction.** The read view starts at the first plain read of any table, so later reads can be stale. A stale link is rejected by the unique index. But two cases stay silent: a supersede or cascade can miss a new open child, and `passIfNotCurrent()` can compare with a stale row and return `false` without a write. If you cannot put the call first, pass `supersede` explicitly and do not rely on `passIfNotCurrent()`.
4. **A lock wait timeout on PostgreSQL is not a `DeadlockException`.** When `lock_timeout` fires (SQLSTATE 55P03), the caller gets a plain `QueryException`. Laravel does not retry it. Do not catch it and commit.

### Limitation: two connections

Take the case where movements are on one connection and resources are on another. If you wrap the package call in a transaction on the movement connection only, the package releases the resource lock before you commit. A second request can then take the lock and miss your writes. So **with two connections, do not wrap the package call in your own transaction on the movement connection.** The package cannot fix this.

## Events

Both events implement `ShouldDispatchAfterCommit`. Laravel dispatches them after the outermost transaction commits and drops them if it rolls back. A listener that throws cannot roll back the `pass()` or `complete()` that fired it.

### `Passed`

Fires after a successful `pass()` or `passIfNotCurrent()` that wrote a movement:

```php
use Hasyirin\KPI\Events\Passed;

Event::listen(function (Passed $event) {
    $event->current;     // the Movement that was just created
    $event->superseded;  // the Movement this pass closed by supersession, or null
});
```

`$event->previous` of v3 is now `$event->superseded`.

`$superseded` is `null` when no movement was closed. That is the case when the target had open children, when it had `expects_children`, when you passed `supersede: false`, or when another request had already closed it. To read the movement that was last written on the level, use `$event->current->previous`.

### `Completed`

Fires once for each movement that closes, whatever the cause:

```php
use Hasyirin\KPI\Events\Completed;

Event::listen(function (Completed $event) {
    $event->movement;  // the movement that just closed
});
```

Causes:
- A direct `$movement->complete()`.
- A cascade: the `complete()` of a parent closes each open descendant.
- Supersession by `pass()`.

A row that was already closed fires no event.

## Testing

```bash
composer test
```

The suite runs on SQLite. `lockForUpdate()` does nothing on SQLite, and the `CHECK` constraints exist only on the other databases. To run the suite on another database, set `DB_CONNECTION` (`pgsql`, `mysql` or `mariadb`), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. The suite drops all tables in that database.

### Race check

`race/run.php` starts two PHP processes against a real database. It checks the locks and retries of this package. Use it before a release.

```bash
DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=kpi DB_USERNAME=postgres DB_PASSWORD=secret \
    php8.4 race/run.php pgsql
```

- The first argument is `pgsql`, `mysql` or `mariadb`. An optional second argument runs only the scenarios whose name starts with it.
- `DB_HOST`, `DB_PORT`, `DB_DATABASE` and `DB_USERNAME` are required. `DB_PASSWORD` is optional.
- Use a throwaway database. The harness drops and creates the tables `movements`, `holidays`, `recurring_holidays`, `tasks` and `race_resources`.
- Each scenario runs 5 times. The command exits with a non-zero code if a scenario fails.

The check ran on PostgreSQL 17, MySQL 8.4 and MariaDB 11.8. MariaDB ran twice: with `innodb_snapshot_isolation` on and off. The harness prints the setting at the start.

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
