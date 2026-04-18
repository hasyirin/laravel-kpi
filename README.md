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

### `pass()`

Record a status transition. The previous open movement is auto-completed with `received_at` of the new one.

```php
$task   = Task::create([...]);
$user   = auth()->user();
$system = $robot;

$task->pass(
    status:     'open',
    sender:     $system,          // who/what triggered the transition (optional)
    actor:     $user,             // who is now responsible (optional)
    receivedAt: now(),            // defaults to now()
    notes:      'Created via API',
    properties: ['source' => 'web'],
);
```

You can also pass a `BackedEnum`:

```php
enum TaskStatus: string {
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Closed     = 'closed';
}

$task->pass(TaskStatus::InProgress, actor: $user);
```

`pass()` runs inside a DB transaction — any failure rolls back the completion of the previous movement and the creation of the new one.

### `passIfNotCurrent()`

Only creates a new movement if the current one doesn't already match the given status **and** actor. Returns `false` otherwise:

```php
$movement = $task->passIfNotCurrent(TaskStatus::Open, actor: $user);
// Movement instance on change, false on no-op.
```

### Reading movements

```php
$task->movement;   // MorphOne — latest non-completed movement
$task->movements;  // MorphMany — full history
```

### Computed attributes on `Movement`

| Attribute | Description |
| --- | --- |
| `period` | Stored on save. Ratio of worked time to scheduled time on a completed movement. |
| `hours`  | Stored on save. Worked time in hours. |
| `interval` | Accessor. `hours * 3600` in seconds. |
| `formatted_period` | Accessor. Falls back to an on-the-fly calculation for incomplete movements. |
| `formatted_interval` | Accessor. Human-readable duration (e.g. `2 hours 15 minutes`). |
| `formatted_received_at` | Accessor. `received_at` formatted via `config('kpi.formats.datetime')`. |

## Events

A `Hasyirin\KPI\Events\Passed` event fires after every successful `pass()`:

```php
use Hasyirin\KPI\Events\Passed;

Event::listen(function (Passed $event) {
    $event->current;   // Movement that was just created
    $event->previous;  // The movement it superseded, or null
});
```

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
