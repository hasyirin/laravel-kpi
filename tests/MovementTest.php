<?php

use Hasyirin\KPI\Events\Completed;
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

it('does not complete previous root when supersede is false', function () {
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('open');
    $task->pass('in_progress', supersede: false);

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

// --- Completed event ---

it('Completed event is constructable with a Movement', function () {
    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open');

    $event = new Completed($movement);

    expect($event->movement)->toBe($movement);
});

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

    // All four should still be open
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

// --- Multi-root supersede footgun ---

it('Model::pass() with default supersede on multiple open roots closes only the most-recent-by-received_at', function () {
    Carbon::setTestNow('2025-01-01 09:00');
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('first');

    Carbon::setTestNow('2025-01-01 10:00');
    $second = $task->pass('second', supersede: false);  // both open, two concurrent leaf roots

    Carbon::setTestNow('2025-01-01 11:00');
    $third = $task->pass('third');  // default supersede: closes most recent root only

    expect($first->fresh()->completed_at)->toBeNull()                              // older root left orphaned-open
        ->and($second->fresh()->completed_at?->toDateTimeString())->toBe('2025-01-01 11:00:00')
        ->and($third->previous_id)->toBe($second->id);

    Carbon::setTestNow();
});
