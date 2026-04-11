<?php

use Hasyirin\KPI\Events\Passed;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Tests\TestSupport\Enums\TaskStatus;
use Hasyirin\KPI\Tests\TestSupport\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

// --- pass() ---

it('creates a movement with the given status', function () {
    $task = Task::create(['title' => 'Test']);

    $movement = $task->pass('open');

    expect($movement)->toBeInstanceOf(Movement::class)
        ->and($movement->status)->toBe('open')
        ->and($movement->movable_type)->toBe(Task::class)
        ->and($movement->movable_id)->toBe($task->id);
});

it('creates a movement with a BackedEnum status', function () {
    $task = Task::create(['title' => 'Test']);

    $movement = $task->pass(TaskStatus::Open);

    // DB stores the string value; refresh to read from DB
    expect($movement->fresh()->status)->toBe('open');
});

it('sets sender and actor on movement', function () {
    $task = Task::create(['title' => 'Test']);
    $sender = Task::create(['title' => 'Sender']);
    $actor = Task::create(['title' => 'Actor']);

    $movement = $task->pass('open', sender: $sender, actor: $actor);

    expect($movement->sender_id)->toBe($sender->id)
        ->and($movement->sender_type)->toBe(Task::class)
        ->and($movement->actor_id)->toBe($actor->id)
        ->and($movement->actor_type)->toBe(Task::class);
});

it('sets received_at to now when not provided', function () {
    Carbon::setTestNow('2025-06-01 10:00:00');

    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open');

    expect($movement->received_at->toDateTimeString())->toBe('2025-06-01 10:00:00');

    Carbon::setTestNow();
});

it('accepts a custom received_at', function () {
    $task = Task::create(['title' => 'Test']);
    $receivedAt = Carbon::parse('2025-03-15 09:00:00');

    $movement = $task->pass('open', receivedAt: $receivedAt);

    expect($movement->received_at->toDateTimeString())->toBe('2025-03-15 09:00:00');
});

it('stores notes and properties', function () {
    $task = Task::create(['title' => 'Test']);

    $movement = $task->pass('open', notes: 'Some note', properties: ['key' => 'value']);

    expect($movement->notes)->toBe('Some note')
        ->and($movement->properties)->toBe(['key' => 'value']);
});

it('completes the previous movement when passing', function () {
    Carbon::setTestNow('2025-01-01 08:00:00');
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('open');

    Carbon::setTestNow('2025-01-01 10:00:00');
    $actor = Task::create(['title' => 'Actor']);
    $second = $task->pass('in_progress', actor: $actor);

    $first->refresh();

    expect($first->completed_at)->not->toBeNull()
        ->and($first->completed_at->toDateTimeString())->toBe('2025-01-01 10:00:00')
        ->and($second->previous_id)->toBe($first->id);

    Carbon::setTestNow();
});

it('sets actor on previous movement when completing it', function () {
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('open');

    $actor = Task::create(['title' => 'Actor']);
    $task->pass('closed', actor: $actor);

    $first->refresh();

    expect($first->actor_id)->toBe($actor->id)
        ->and($first->actor_type)->toBe(Task::class);
});

it('does not complete previous movement when completesLastMovement is false', function () {
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('open');
    $task->pass('in_progress', completesLastMovement: false);

    $first->refresh();

    expect($first->completed_at)->toBeNull();
});

it('dispatches Passed event', function () {
    Event::fake([Passed::class]);

    $task = Task::create(['title' => 'Test']);
    $task->pass('open');

    Event::assertDispatched(Passed::class, function (Passed $event) {
        return $event->current->status === 'open' && $event->previous === null;
    });
});

it('dispatches Passed event with previous movement', function () {
    $task = Task::create(['title' => 'Test']);
    $task->pass('open');

    Event::fake([Passed::class]);
    $task->pass('closed');

    Event::assertDispatched(Passed::class, function (Passed $event) {
        return $event->current->status === 'closed' && $event->previous !== null;
    });
});

// --- passIfNotCurrent() ---

it('returns false when status and actor are the same', function () {
    $task = Task::create(['title' => 'Test']);
    $actor = Task::create(['title' => 'Actor']);

    $task->pass('open', actor: $actor);
    $result = $task->passIfNotCurrent('open', actor: $actor);

    expect($result)->toBeFalse();
});

it('creates movement when status differs', function () {
    $task = Task::create(['title' => 'Test']);
    $task->pass('open');

    $result = $task->passIfNotCurrent('closed');

    expect($result)->toBeInstanceOf(Movement::class)
        ->and($result->status)->toBe('closed');
});

it('creates movement when actor differs', function () {
    $task = Task::create(['title' => 'Test']);
    $actor1 = Task::create(['title' => 'Actor 1']);
    $actor2 = Task::create(['title' => 'Actor 2']);

    $task->pass('open', actor: $actor1);
    $result = $task->passIfNotCurrent('open', actor: $actor2);

    expect($result)->toBeInstanceOf(Movement::class);
});

it('creates movement when there is no current movement', function () {
    $task = Task::create(['title' => 'Test']);

    $result = $task->passIfNotCurrent('open');

    expect($result)->toBeInstanceOf(Movement::class)
        ->and($result->status)->toBe('open');
});

// --- Movement model ---

it('calculates period and hours when completed_at is set', function () {
    // Wed Jan 1, 8:00 to 9:00 = 1 hour, period 0.1111
    Carbon::setTestNow('2025-01-01 08:00:00');
    $task = Task::create(['title' => 'Test']);
    $task->pass('open');

    Carbon::setTestNow('2025-01-01 09:00:00');
    $task->pass('closed');

    // The first movement was completed by pass() — verify calculated values
    $completed = Movement::where('status', 'open')->first();

    expect($completed->period)->toBe(0.1111)
        ->and($completed->hours)->toBe(1.0);

    Carbon::setTestNow();
});

it('sets period and hours to null when completed_at is empty', function () {
    $movement = new Movement([
        'movable_id' => 1,
        'movable_type' => Task::class,
        'status' => 'open',
        'received_at' => Carbon::parse('2025-01-01 08:00'),
        'properties' => [],
    ]);

    $movement->save();

    expect($movement->period)->toBeNull()
        ->and($movement->hours)->toBeNull();
});

it('computes interval attribute in seconds from hours', function () {
    $movement = new Movement([
        'movable_id' => 1,
        'movable_type' => Task::class,
        'status' => 'open',
        'received_at' => Carbon::parse('2025-01-01 08:00'),
        'completed_at' => Carbon::parse('2025-01-01 10:00'),
        'properties' => [],
    ]);

    $movement->save();

    // 2 hours = 7200 seconds
    expect($movement->interval)->toBe(7200.0);
});

it('provides formatted period for incomplete movements', function () {
    $movement = new Movement([
        'movable_id' => 1,
        'movable_type' => Task::class,
        'status' => 'open',
        'received_at' => Carbon::parse('2025-01-01 08:00'),
        'properties' => [],
    ]);

    $movement->save();

    // formattedPeriod falls back to calculate() when period is null
    expect($movement->formattedPeriod)->toBeNumeric();
});

// --- Relationships ---

it('tracks movement chain via morphOne', function () {
    $task = Task::create(['title' => 'Test']);

    $task->pass('open');
    $task->pass('in_progress');

    $task->refresh();

    // movement() returns the latest non-completed movement
    expect($task->movement)->toBeInstanceOf(Movement::class)
        ->and($task->movement->status)->toBe('in_progress');
});

it('tracks all movements via morphMany', function () {
    $task = Task::create(['title' => 'Test']);

    $task->pass('open');
    $task->pass('in_progress');
    $task->pass('closed');

    expect($task->movements)->toHaveCount(3);
});
