<?php

use Hasyirin\KPI\Events\Completed;
use Hasyirin\KPI\Events\Passed;
use Hasyirin\KPI\Models\Holiday;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Tests\TestCase;
use Hasyirin\KPI\Tests\TestSupport\Enums\TaskStage;
use Hasyirin\KPI\Tests\TestSupport\Enums\TaskStatus;
use Hasyirin\KPI\Tests\TestSupport\Models\CustomMovement;
use Hasyirin\KPI\Tests\TestSupport\Models\StringKeyTask;
use Hasyirin\KPI\Tests\TestSupport\Models\Task;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

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
        return $event->current->status === 'open' && $event->superseded === null;
    });
});

it('dispatches Passed event with the superseded movement', function () {
    $task = Task::create(['title' => 'Test']);
    $task->pass('open');

    Event::fake([Passed::class]);
    $task->pass('closed');

    Event::assertDispatched(Passed::class, function (Passed $event) {
        return $event->current->status === 'closed' && $event->superseded !== null;
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

it('calculates period and seconds when completed_at is set', function () {
    // Wed Jan 1, 8:00 to 9:00 = 1 hour, period 0.1111
    Carbon::setTestNow('2025-01-01 08:00:00');
    $task = Task::create(['title' => 'Test']);
    $task->pass('open');

    Carbon::setTestNow('2025-01-01 09:00:00');
    $task->pass('closed');

    // The first movement was completed by pass() — verify calculated values
    $completed = Movement::where('status', 'open')->first();

    expect($completed->period)->toBe(0.1111)
        ->and($completed->seconds)->toBe(3600);

    Carbon::setTestNow();
});

it('sets period and seconds to null when completed_at is empty', function () {
    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));

    expect($movement->period)->toBeNull()
        ->and($movement->seconds)->toBeNull();
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
    $movement->position = 1;
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
    $movement->position = 1;
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
    $child->position = 1;
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

    foreach (['a', 'b', 'c'] as $index => $status) {
        $child = new Movement([
            'parent_id' => $root->id,
            'movable_id' => $task->id,
            'movable_type' => Task::class,
            'status' => $status,
            'received_at' => now(),
            'properties' => [],
        ]);
        $child->position = $index + 1;
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
    $child->position = 1;
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
    $m = $task->pass('open', receivedAt: Carbon::parse('2025-03-15 08:00'));

    $at = Carbon::parse('2025-03-15 14:00');
    $m->complete($at);

    expect($m->fresh()->completed_at->toDateTimeString())->toBe('2025-03-15 14:00:00');
});

it('complete() is a no-op when already completed', function () {
    $task = Task::create(['title' => 'Test']);
    $m = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));

    $m->complete(Carbon::parse('2025-01-01 10:00'));
    $first = $m->fresh()->completed_at;

    $m->complete(Carbon::parse('2025-01-01 11:00'));  // should not overwrite
    expect($m->fresh()->completed_at->toDateTimeString())
        ->toBe($first->toDateTimeString());
});

it('complete() cascades through open descendants with same timestamp', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));

    $child = new Movement([
        'parent_id' => $root->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'sub',
        'received_at' => Carbon::parse('2025-01-01 08:00'),
        'properties' => [],
    ]);
    $child->position = 1;
    $child->save();

    $grandchild = new Movement([
        'parent_id' => $child->id,
        'movable_id' => $task->id,
        'movable_type' => Task::class,
        'status' => 'subsub',
        'received_at' => Carbon::parse('2025-01-01 08:00'),
        'properties' => [],
    ]);
    $grandchild->position = 1;
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
    $child->position = 1;
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

it('Movement::pass() links to the latest sibling and supersedes it by default', function () {
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

    $devin->complete();

    expect($root->pass('with_eve')->previous_id)->toBe($devin->id);

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

it('Movement::pass() dispatches Passed event with superseded null when no supersede happened', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');

    Event::fake([Passed::class]);
    $root->pass('with_bob');  // first child, no previous sibling

    Event::assertDispatched(Passed::class, function (Passed $event) {
        return $event->current->status === 'with_bob' && $event->superseded === null;
    });
});

it('Movement::pass() dispatches Passed event with superseded when supersede fires', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('agency');
    $bob = $root->pass('with_bob');

    Event::fake([Passed::class]);
    $root->pass('with_devin');  // supersedes bob

    Event::assertDispatched(Passed::class, function (Passed $event) use ($bob) {
        return $event->current->status === 'with_devin' && $event->superseded?->id === $bob->id;
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

it('Passed::superseded is null when no supersede happened on Model::pass()', function () {
    $task = Task::create(['title' => 'Test']);

    Event::fake([Passed::class]);
    $task->pass('open');

    Event::assertDispatched(Passed::class, fn (Passed $e) => $e->superseded === null);
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
        ->and($m4->previous_id)->toBe($m1->id);    // latest root before review

    // All four should still be open
    expect($m1->fresh()->completed_at)->toBeNull()
        ->and($m2->fresh()->completed_at)->toBeNull()
        ->and($m3->fresh()->completed_at)->toBeNull()
        ->and($m4->fresh()->completed_at)->toBeNull();

    // Open roots count
    expect($task->movements()->roots()->open()->count())->toBe(2);

    // Open children of #1
    expect($m1->children()->open()->count())->toBe(2);

    // $task->movement returns the last-written open movement
    expect($task->fresh()->movement->id)->toBe($m4->id);
});

// --- Stored KPI values ---

it('keeps the stored KPI of a completed movement when an unrelated attribute changes', function () {
    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2024-01-01 08:00'));  // Mon
    $movement->complete(Carbon::parse('2024-01-03 17:00'));                          // Wed: 27 hours
    Holiday::create(['name' => 'Tuesday', 'date' => '2024-01-02']);

    $movement->update(['notes' => 'reviewed']);

    expect($movement->fresh()->seconds)->toBe(97200)
        ->and($movement->fresh()->period)->toBe(3.0);
});

it('recalculates the stored KPI of a completed movement on demand', function () {
    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2024-01-01 08:00'));
    $movement->complete(Carbon::parse('2024-01-03 17:00'));
    Holiday::create(['name' => 'Tuesday', 'date' => '2024-01-02']);

    $movement->recalculate();

    expect($movement->fresh()->seconds)->toBe(64800)
        ->and($movement->fresh()->period)->toBe(2.0);
});

it('recalculates a completed movement when its received_at changes', function () {
    $task = Task::create(['title' => 'Test']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2024-01-01 08:00'));
    $movement->complete(Carbon::parse('2024-01-03 17:00'));

    $movement->update(['received_at' => Carbon::parse('2024-01-02 08:00')]);

    expect($movement->fresh()->seconds)->toBe(64800);
});

// --- Stale instances and lock order ---

it('does not complete a movement again through a stale instance', function () {
    Event::fake([Completed::class]);
    $task = Task::create(['title' => 'Test']);
    $a = $task->pass('a', receivedAt: Carbon::parse('2024-01-01 08:00'));
    $task->pass('b', receivedAt: Carbon::parse('2024-01-01 11:00'));  // supersedes a through a fresh instance

    $a->complete(Carbon::parse('2024-01-01 17:00'));

    expect($a->completed_at->toDateTimeString())->toBe('2024-01-01 11:00:00')
        ->and($a->fresh()->completed_at->toDateTimeString())->toBe('2024-01-01 11:00:00')
        ->and($a->fresh()->seconds)->toBe(10800);
    Event::assertDispatchedTimes(Completed::class, 1);
});

it('refuses to pass from a movement completed through another instance', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('root');
    $stale = Movement::find($root->id);
    $root->complete();

    expect(fn () => $stale->pass('late'))->toThrow(LogicException::class)
        ->and($root->children()->count())->toBe(0);
});

it('locks the resource and the movement before it reads the children when completing it', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('root');
    $root->pass('child');

    DB::enableQueryLog();
    $root->complete();
    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'select'))->map(fn (string $sql) => str_replace(['"', '`'], '', $sql))->values();

    expect($selects[0])->toContain('from tasks where id = ?')
        ->and($selects[1])->toContain('where movements.id = ?')
        ->and($selects[2])->toContain('where movements.parent_id = ?');
});

it('locks a movement before its children when passing from it', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('root');
    $root->pass('child');

    DB::enableQueryLog();
    $root->pass('next');
    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'select'))->map(fn (string $sql) => str_replace(['"', '`'], '', $sql))->values();

    expect($selects[0])->toContain('from tasks where id = ?')
        ->and($selects[1])->toContain('where movements.id = ?')
        ->and($selects[2])->toContain('level_key');
});

// --- Same-second movements ---

it('supersedes the newest root when two roots share a received_at second', function () {
    Carbon::setTestNow('2024-01-01 09:00:00');
    $task = Task::create(['title' => 'Test']);
    $first = $task->pass('first');
    $second = $task->pass('second', supersede: false);

    $third = $task->pass('third');

    expect($third->previous_id)->toBe($second->id)
        ->and($second->fresh()->completed_at)->not->toBeNull()
        ->and($first->fresh()->completed_at)->toBeNull();

    Carbon::setTestNow();
});

it('supersedes the newest child when two children share a received_at second', function () {
    Carbon::setTestNow('2024-01-01 09:00:00');
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('root');
    $bob = $root->pass('with_bob');
    $dave = $root->pass('with_dave', supersede: false);

    $eve = $root->pass('with_eve');

    expect($eve->previous_id)->toBe($dave->id)
        ->and($dave->fresh()->completed_at)->not->toBeNull()
        ->and($bob->fresh()->completed_at)->toBeNull();

    Carbon::setTestNow();
});

it('compares passIfNotCurrent() with the newest root when two roots share a received_at second', function () {
    Carbon::setTestNow('2024-01-01 09:00:00');
    $task = Task::create(['title' => 'Test']);
    $task->pass('first');
    $task->pass('second', supersede: false);

    expect($task->passIfNotCurrent('second'))->toBeFalse();

    Carbon::setTestNow();
});

it('compares Movement::passIfNotCurrent() with the newest child when two children share a received_at second', function () {
    Carbon::setTestNow('2024-01-01 09:00:00');
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('root');
    $root->pass('with_bob');
    $root->pass('with_dave', supersede: false);

    expect($root->passIfNotCurrent('with_dave'))->toBeFalse();

    Carbon::setTestNow();
});

// --- Configured movement model and connection ---

it('resolves movement relations to the configured movement model', function () {
    config()->set('kpi.models.movement', CustomMovement::class);
    $task = Task::create(['title' => 'Test']);
    $task->pass('first');
    $root = $task->pass('root');
    $child = $root->pass('child');

    expect($root->previous)->toBeInstanceOf(CustomMovement::class)
        ->and($root->children->first())->toBeInstanceOf(CustomMovement::class)
        ->and($child)->toBeInstanceOf(CustomMovement::class)
        ->and($child->parent)->toBeInstanceOf(CustomMovement::class);
});

it('stores movements on the connection of the movable model', function () {
    config()->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('tenant');
    foreach (TestCase::MIGRATIONS as $migration) {
        $this->migration($migration)->up();
    }
    Schema::create('tasks', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    DB::setDefaultConnection('testing');

    $task = Task::on('tenant')->create(['title' => 'Test']);
    $root = $task->pass('open');
    $root->pass('child');
    $task->pass('closed', supersede: true);

    expect($task->movements()->count())->toBe(3)
        ->and($task->movement->status)->toBe('closed')
        ->and(DB::connection('testing')->table('movements')->count())->toBe(0);
});

// --- Event timing ---

it('lets Passed listeners read the new current movement of the model', function () {
    $task = Task::create(['title' => 'Test']);
    $task->pass('open');
    $seen = null;
    Event::listen(Passed::class, function (Passed $event) use (&$seen) {
        $seen = $event->current->movable->movement->status;
    });

    $task->pass('closed');

    expect($seen)->toBe('closed');
});

it('does not dispatch movement events when the surrounding transaction rolls back', function () {
    $dispatched = [];
    Event::listen(Passed::class, function () use (&$dispatched) {
        $dispatched[] = Passed::class;
    });
    Event::listen(Completed::class, function () use (&$dispatched) {
        $dispatched[] = Completed::class;
    });
    $task = Task::create(['title' => 'Test']);

    try {
        DB::transaction(function () use ($task) {
            $task->pass('a');
            $task->pass('b');

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($dispatched)->toBe([]);
});

it('dispatches movement events in order once the surrounding transaction commits', function () {
    $dispatched = [];
    Event::listen(Passed::class, function () use (&$dispatched) {
        $dispatched[] = Passed::class;
    });
    Event::listen(Completed::class, function () use (&$dispatched) {
        $dispatched[] = Completed::class;
    });
    $task = Task::create(['title' => 'Test']);

    DB::transaction(function () use ($task) {
        $task->pass('a');
        $task->pass('b');
    });

    expect($dispatched)->toBe([Passed::class, Completed::class, Passed::class]);
});

// --- passIfNotCurrent() value types ---

it('passIfNotCurrent() returns false when the current root has the same int-backed enum status', function () {
    $task = Task::create(['title' => 'Test']);
    $task->pass(TaskStage::Review);

    expect($task->passIfNotCurrent(TaskStage::Review))->toBeFalse();
});

it('Movement::passIfNotCurrent() returns false when the latest child has the same int-backed enum status', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('root');
    $root->pass(TaskStage::Review);

    expect($root->passIfNotCurrent(TaskStage::Review))->toBeFalse();
});

it('passIfNotCurrent() matches an actor whose key type differs from the stored actor_id', function () {
    $task = Task::create(['title' => 'Test']);
    $actor = StringKeyTask::create(['title' => 'Actor']);
    $task->pass('review', actor: $actor);

    expect($task->passIfNotCurrent('review', actor: $actor))->toBeFalse();
});

it('Movement::passIfNotCurrent() matches an actor whose key type differs from the stored actor_id', function () {
    $task = Task::create(['title' => 'Test']);
    $actor = StringKeyTask::create(['title' => 'Actor']);
    $root = $task->pass('root');
    $root->pass('review', actor: $actor);

    expect($root->passIfNotCurrent('review', actor: $actor))->toBeFalse();
});

// --- Deletes ---

it('keeps later movements and other tracks when a movement is deleted', function () {
    $task = Task::create(['title' => 'Test']);
    $payment = $task->pass('payment');
    $fulfillment = $task->pass('fulfillment', supersede: false);
    $packing = $fulfillment->pass('packing');

    $payment->delete();

    expect($fulfillment->fresh())->not->toBeNull()
        ->and($fulfillment->fresh()->previous_id)->toBeNull()
        ->and($packing->fresh())->not->toBeNull();
});

it('deletes the children of a deleted movement', function () {
    $task = Task::create(['title' => 'Test']);
    $root = $task->pass('root');
    $child = $root->pass('child');

    $root->delete();

    expect(Movement::find($child->id))->toBeNull();
});

// --- Multi-root supersede footgun ---

it('Model::pass() with default supersede on multiple open roots closes only the last written', function () {
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

// --- Stored values and kpi() ---

it('keeps stored values when an unrelated column is saved', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $movement->complete(Carbon::parse('2025-01-01 09:00'));
    Holiday::create(['name' => 'H', 'date' => '2025-01-01']);

    $movement->update(['notes' => 'changed']);

    expect($movement->fresh()->seconds)->toBe(3600);
});

it('recalculates on request', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $movement->complete(Carbon::parse('2025-01-01 09:00'));
    Holiday::create(['name' => 'H', 'date' => '2025-01-01']);

    expect($movement->recalculate()->seconds)->toBe(0)
        ->and($movement->fresh()->seconds)->toBe(0);
});

it('returns stored values with empty metadata for a closed movement and ignores at', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $movement->complete(Carbon::parse('2025-01-01 09:00'));

    expect($movement->kpi(now()->addYear())->seconds)->toBe(3600)
        ->and($movement->kpi()->period)->toBe(0.1111)
        ->and($movement->kpi()->metadata->scheduledDays)->toBe(0);
});

it('returns a live calculation for an open movement', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));

    expect($movement->kpi(Carbon::parse('2025-01-01 09:00'))->seconds)->toBe(3600)
        ->and($movement->kpi(Carbon::parse('2025-01-01 09:00'))->metadata->scheduledDays)->toBe(1);
});

it('returns zero for an except status, open or closed', function () {
    $task = Task::create(['title' => 'T']);
    config()->set('kpi.status.'.$task->getMorphClass().'.except', [TaskStage::OnHold, 'paused']);

    $open = $task->pass('paused', receivedAt: Carbon::parse('2025-01-01 08:00'), supersede: false);
    $closed = $task->pass(TaskStage::OnHold, receivedAt: Carbon::parse('2025-01-01 08:00'), supersede: false);
    $closed->complete(Carbon::parse('2025-01-01 09:00'));
    $normal = $task->pass('normal', receivedAt: Carbon::parse('2025-01-01 08:00'), supersede: false);
    $normal->complete(Carbon::parse('2025-01-01 09:00'));

    expect($open->kpi(Carbon::parse('2025-01-01 09:00'))->seconds)->toBe(0)
        ->and($closed->fresh()->seconds)->toBe(0)
        ->and($closed->fresh()->kpi()->seconds)->toBe(0)
        ->and($normal->fresh()->seconds)->toBe(3600);
});

it('reads a missing status config as no excepted statuses', function () {
    config()->set('kpi.status', null);
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));

    expect($movement->isExcepted())->toBeFalse();
});

it('rejects a backwards close and seconds on an open row', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('The stub does not add CHECK constraints on SQLite.');
    }

    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $movement->complete(Carbon::parse('2025-01-01 09:00'));
    $open = $task->pass('again', receivedAt: Carbon::parse('2025-01-02 08:00'));

    expect(fn () => DB::table('movements')->where('id', $movement->id)->update(['completed_at' => '2000-01-01 00:00:00']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('movements')->where('id', $open->id)->update(['seconds' => 5]))
        ->toThrow(QueryException::class);
});

// --- complete() through the resource lock ---

it('closes descendants deepest first at the requested time', function () {
    $task = Task::create(['title' => 'T']);
    $root = $task->pass('root', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $child = $root->pass('child', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $grandchild = $child->pass('grandchild', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $order = [];
    Event::listen(Completed::class, function (Completed $event) use (&$order) {
        $order[] = $event->movement->id;
    });

    $root->complete(Carbon::parse('2025-01-01 12:00'));

    expect($order)->toBe([$grandchild->id, $child->id, $root->id])
        ->and($grandchild->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 12:00:00')
        ->and($child->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 12:00:00')
        ->and($root->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 12:00:00');
});

it('never stores completed_at before received_at', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 10:00'));

    $movement->complete(Carbon::parse('2025-01-01 09:00'));

    expect($movement->fresh()->completed_at->equalTo(Carbon::parse('2025-01-01 10:00')))->toBeTrue()
        ->and($movement->fresh()->seconds)->toBe(0);
});

it('stores zero time on a descendant that started after the close time', function () {
    $task = Task::create(['title' => 'T']);
    $root = $task->pass('root', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $child = $root->pass('child', receivedAt: Carbon::parse('2025-01-01 11:00'));

    $root->complete(Carbon::parse('2025-01-01 10:00'));

    expect($root->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 10:00:00')
        ->and($child->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 11:00:00')
        ->and($child->fresh()->seconds)->toBe(0);
});

it('converts a given time to the app time zone', function () {
    config()->set('app.timezone', 'UTC');
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $at = Carbon::parse('2025-01-01 17:00', 'Asia/Kuala_Lumpur');

    $movement->complete($at);

    expect(DB::table('movements')->value('completed_at'))->toStartWith('2025-01-01 09:00:00')
        ->and($at->timezoneName)->toBe('Asia/Kuala_Lumpur');
});

it('takes a new default time on each attempt', function () {
    Carbon::setTestNow('2025-01-01 09:00:00');
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open');
    Carbon::setTestNow('2025-01-01 10:00:00');
    $attempts = 0;
    Movement::saving(function () use (&$attempts) {
        if (++$attempts === 1) {
            Carbon::setTestNow('2025-01-01 11:00:00');

            throw new UniqueConstraintViolationException('testing', 'insert', [], new RuntimeException('unique'));
        }
    });

    $movement->complete();

    expect($movement->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 11:00:00');

    Carbon::setTestNow();
});

it('returns without an event when the row is already closed, even from a stale instance', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $stale = Movement::find($movement->id);
    $movement->complete();
    Event::fake([Completed::class]);

    $result = $stale->complete();

    expect($result)->toBe($stale)
        ->and($stale->completed_at)->not->toBeNull();
    Event::assertNotDispatched(Completed::class);
});

it('throws on a hard-deleted movement', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open');
    $stale = Movement::find($movement->id);
    DB::table('movements')->where('id', $movement->id)->delete();

    expect(fn () => $stale->complete())->toThrow(LogicException::class);
});

it('throws when the resource row is gone', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open');
    DB::table('tasks')->delete();

    expect(fn () => $movement->complete())->toThrow(LogicException::class)
        ->and($movement->fresh()->completed_at)->toBeNull();
});

it('dispatches Completed once after a retried attempt', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $attempts = 0;
    Movement::saving(function () use (&$attempts) {
        if (++$attempts === 1) {
            throw new UniqueConstraintViolationException('testing', 'insert', [], new RuntimeException('unique'));
        }
    });
    $completed = 0;
    Event::listen(Completed::class, function () use (&$completed) {
        $completed++;
    });

    $movement->complete(Carbon::parse('2025-01-01 09:00'));

    expect($attempts)->toBe(2)
        ->and($completed)->toBe(1)
        ->and($movement->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 09:00:00');
});

it('takes the instance state from the row after a failed attempt', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));

    expect(fn () => DB::transaction(function () use ($movement) {
        $movement->complete(Carbon::parse('2025-01-01 09:00'));

        DB::table('missing_table')->get();
    }))->toThrow(QueryException::class);

    expect($movement->fresh()->completed_at)->toBeNull();

    $movement->complete(Carbon::parse('2025-01-01 10:00'));

    expect($movement->completed_at->toDateTimeString())->toBe('2025-01-01 10:00:00')
        ->and($movement->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 10:00:00')
        ->and($movement->fresh()->seconds)->toBe(7200);
});

it('discards unsaved attribute changes when it reads the row', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $movement->notes = 'unsaved';

    $movement->complete(Carbon::parse('2025-01-01 09:00'));

    expect($movement->notes)->toBeNull()
        ->and($movement->fresh()->notes)->toBeNull();
});

it('back-fills the given actor only when the row has none', function () {
    $task = Task::create(['title' => 'T']);
    $other = Task::create(['title' => 'Other']);
    $actor = Task::create(['title' => 'Actor']);
    $existing = Task::create(['title' => 'Existing']);
    $without = $task->pass('without');
    $task->pass('next', actor: $actor);
    $with = $other->pass('with', actor: $existing);
    $other->pass('next', actor: $actor);

    expect($without->fresh()->actor_id)->toBe($actor->id)
        ->and($without->fresh()->actor_type)->toBe(Task::class)
        ->and($with->fresh()->actor_id)->toBe($existing->id);
});

it('does not back-fill descendants', function () {
    $task = Task::create(['title' => 'T']);
    $actor = Task::create(['title' => 'Actor']);
    $root = $task->pass('root', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $child = $root->pass('child', receivedAt: Carbon::parse('2025-01-01 08:00'));

    $task->pass('next', actor: $actor, supersede: true);

    expect($child->fresh()->actor_id)->toBeNull()
        ->and($root->fresh()->actor_id)->toBe($actor->id);
});

it('does not back-fill a target that closed after it was read', function () {
    $task = Task::create(['title' => 'T']);
    $actor = Task::create(['title' => 'Actor']);
    $target = $task->pass('target', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $closed = false;
    DB::listen(function ($query) use (&$closed, $target) {
        if (! $closed && str_contains($query->sql, 'level_key') && str_contains($query->sql, 'completed_at')) {
            $closed = true;
            DB::table('movements')->where('id', $target->id)->update(['completed_at' => '2025-01-01 09:00:00']);
        }
    });

    $task->pass('next', actor: $actor);

    expect($closed)->toBeTrue()
        ->and($target->fresh()->actor_id)->toBeNull()
        ->and($target->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 09:00:00');
});

it('reads open children without a lock when completing', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite does not emit FOR UPDATE.');
    }

    $task = Task::create(['title' => 'T']);
    $root = $task->pass('root');
    $root->pass('child');
    DB::enableQueryLog();

    $root->complete();

    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'select'))->map(fn (string $sql) => str_replace(['"', '`'], '', $sql))->values();

    expect($selects[0])->toContain('tasks')->toContain('for update')
        ->and($selects[1])->toContain('where movements.id = ?')->toContain('for update')
        ->and($selects[2])->toContain('where movements.parent_id = ?')->not->toContain('for update');
});

// --- One write path ---

it('rejects a duplicate position on a level', function () {
    $task = Task::create(['title' => 'T']);
    $task->pass('a');

    expect(fn () => $task->movements()->make(['status' => 'x', 'received_at' => now()])->forceFill(['position' => 1])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the worked example of the v2 spec', function () {
    $task = Task::create(['title' => 'T']);
    $root1 = $task->pass('alice', supersede: false, expectsChildren: true);
    $bob = $root1->pass('bob', supersede: false);
    $dave = $root1->pass('dave', supersede: false);
    $root4 = $task->pass('charlie', supersede: false);
    $assistant = $bob->pass('assistant');

    expect(Movement::query()->open()->count())->toBe(5)
        ->and($root4->position)->toBe(2)
        ->and($dave->position)->toBe(2)
        ->and($assistant->position)->toBe(1);
});

it('computes level_key in the database and never writes it', function () {
    $task = Task::create(['title' => 'T']);
    $root = $task->pass('a');
    $child = $root->pass('b');

    expect(DB::table('movements')->where('id', $root->id)->value('level_key'))->toEqual(0)
        ->and(DB::table('movements')->where('id', $child->id)->value('level_key'))->toEqual($root->id)
        ->and($child->getAttributes())->not->toHaveKey('level_key')
        ->and($child->fresh()->getAttributes())->toHaveKey('level_key');
});

it('does not write level_key when it saves a row it read', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a');
    $updates = [];
    DB::listen(function ($query) use (&$updates) {
        if (str_starts_with($query->sql, 'update')) {
            $updates[] = $query->sql;
        }
    });

    $first->complete();
    $task->pass('b');
    $first->fresh()->update(['notes' => 'changed']);

    expect($updates)->not->toBeEmpty();
    foreach ($updates as $sql) {
        expect($sql)->not->toContain('level_key');
    }
});

it('links to the latest movement on the level even when closed', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a');
    $first->complete();
    $second = $task->pass('b');

    expect($second->previous_id)->toBe($first->id)->and($second->position)->toBe(2);
});

it('does not collide after a backdated pass', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a', receivedAt: Carbon::parse('2025-01-02 10:00'));
    $backdated = $task->pass('b', receivedAt: Carbon::parse('2025-01-01 10:00'));
    $next = $task->pass('c');

    expect($backdated->previous_id)->toBe($first->id)
        ->and($first->fresh()->seconds)->toBe(0)
        ->and($next->previous_id)->toBe($backdated->id)
        ->and($next->position)->toBe(3);
});

it('reuses the position of a deleted tail', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a');
    $second = $task->pass('b');
    $second->delete();

    $third = $task->pass('c');

    expect($third->position)->toBe(2)
        ->and($third->previous_id)->toBe($first->id);
});

it('keeps working after a head or a mid-chain row is deleted', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a');
    $second = $task->pass('b');
    $third = $task->pass('c');

    $first->delete();
    $afterHead = $task->pass('d');

    $third->delete();
    $afterMid = $task->pass('e');

    expect($second->fresh()->previous_id)->toBeNull()
        ->and($afterHead->position)->toBe(4)
        ->and($afterHead->previous_id)->toBe($third->id)
        ->and($afterHead->fresh()->previous_id)->toBeNull()
        ->and($afterMid->position)->toBe(5)
        ->and($afterMid->previous_id)->toBe($afterHead->id);
});

it('keeps working after a parent is deleted', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a', expectsChildren: true);
    $child = $first->pass('child');
    $grandchild = $child->pass('grandchild');
    $second = $task->pass('b');

    $first->delete();
    $third = $task->pass('c');

    expect(Movement::find($child->id))->toBeNull()
        ->and(Movement::find($grandchild->id))->toBeNull()
        ->and($second->fresh()->previous_id)->toBeNull()
        ->and($third->position)->toBe(3)
        ->and($third->previous_id)->toBe($second->id);
});

it('keeps levels of different resources apart', function () {
    $task = Task::create(['title' => 'T']);
    $other = Task::create(['title' => 'O']);
    $task->pass('a');

    $movement = $other->pass('b');

    expect($movement->position)->toBe(1)
        ->and($movement->previous_id)->toBeNull();
});

it('reads now after the lock', function () {
    Carbon::setTestNow('2025-01-01 10:00:00');
    $task = Task::create(['title' => 'T']);
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'tasks')) {
            Carbon::setTestNow('2025-01-01 11:00:00');
        }
    });

    expect($task->pass('a')->received_at->format('H:i'))->toBe('11:00');

    Carbon::setTestNow();
});

it('takes a new default received_at on each attempt', function () {
    Carbon::setTestNow('2025-01-01 10:00:00');
    $task = Task::create(['title' => 'T']);
    $attempts = 0;
    Movement::saving(function () use (&$attempts) {
        if (++$attempts === 1) {
            Carbon::setTestNow('2025-01-01 11:00:00');

            throw new UniqueConstraintViolationException('testing', 'insert', [], new RuntimeException('unique'));
        }
    });

    expect($task->pass('a')->received_at->format('H:i'))->toBe('11:00');

    Carbon::setTestNow();
});

it('converts a given receivedAt to the app time zone', function () {
    config()->set('app.timezone', 'UTC');
    $task = Task::create(['title' => 'T']);
    $at = Carbon::parse('2025-01-01 17:00', 'Asia/Kuala_Lumpur');

    $movement = $task->pass('a', receivedAt: $at);

    expect(DB::table('movements')->where('id', $movement->id)->value('received_at'))->toStartWith('2025-01-01 09:00:00')
        ->and($at->timezoneName)->toBe('Asia/Kuala_Lumpur');
});

it('skips the close of an already closed row', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('a');
    $stale = Movement::find($movement->id);
    $movement->complete();
    $completedAt = $movement->fresh()->completed_at;
    Event::fake([Completed::class]);

    $stale->complete(now()->addHour());

    Event::assertNotDispatched(Completed::class);
    expect($stale->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($movement->fresh()->completed_at->equalTo($completedAt))->toBeTrue();
});

it('skips the close of a stale target and reports no superseded movement', function () {
    $task = Task::create(['title' => 'T']);
    $root = $task->pass('root', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $child = $root->pass('child', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $closed = false;
    DB::listen(function ($query) use (&$closed, $child) {
        if (! $closed && str_contains($query->sql, 'parent_id') && str_contains($query->sql, 'completed_at')) {
            $closed = true;
            DB::table('movements')->where('id', $child->id)->update(['completed_at' => '2025-01-01 09:00:00']);
        }
    });
    $superseded = 'unset';
    $completed = 0;
    Event::listen(Passed::class, function (Passed $event) use (&$superseded) {
        $superseded = $event->superseded;
    });
    Event::listen(Completed::class, function () use (&$completed) {
        $completed++;
    });

    $next = $root->pass('next');

    expect($closed)->toBeTrue()
        ->and($superseded)->toBeNull()
        ->and($completed)->toBe(0)
        ->and($next->previous_id)->toBe($child->id)
        ->and($child->fresh()->completed_at->toDateTimeString())->toBe('2025-01-01 09:00:00');
});

it('throws when passing from a completed movement, checked inside the lock', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('a');
    $stale = Movement::find($movement->id);
    $movement->complete();
    DB::enableQueryLog();

    expect(fn () => $stale->pass('x'))->toThrow(LogicException::class)
        ->and(fn () => $stale->passIfNotCurrent('x'))->toThrow(LogicException::class)
        ->and(str_replace(['"', '`'], '', DB::getQueryLog()[0]['query']))->toContain('from tasks');
});

it('fills the instance from the row when passing from a movement', function () {
    $task = Task::create(['title' => 'T']);
    $movement = $task->pass('a');
    $stale = Movement::find($movement->id);
    $movement->update(['notes' => 'newer']);
    $stale->notes = 'unsaved';

    $stale->pass('x');

    expect($stale->notes)->toBe('newer')
        ->and($stale->isDirty())->toBeFalse();
});

it('runs passIfNotCurrent inside the lock', function () {
    $task = Task::create(['title' => 'T']);
    $task->pass('a');
    DB::enableQueryLog();

    expect($task->passIfNotCurrent('a'))->toBeFalse();

    $selects = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => str_replace(['"', '`'], '', $sql))->values();

    expect($selects[0])->toContain('from tasks')
        ->and($selects[1])->toContain('from movements');
});

it('runs Movement::passIfNotCurrent inside the lock', function () {
    $task = Task::create(['title' => 'T']);
    $root = $task->pass('a');
    $root->pass('b');
    DB::enableQueryLog();

    expect($root->passIfNotCurrent('b'))->toBeFalse();

    $selects = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => str_replace(['"', '`'], '', $sql))->values();

    expect($selects[0])->toContain('from tasks')
        ->and($selects[1])->toContain('where movements.id = ?');
});

it('compares passIfNotCurrent with the latest open movement, not a closed one', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a', supersede: false);
    $second = $task->pass('b', supersede: false);
    $second->complete();

    expect($task->passIfNotCurrent('a'))->toBeFalse()
        ->and($first->fresh()->completed_at)->toBeNull();
});

it('compares a BackedEnum status and a string-key actor as strings', function () {
    $task = Task::create(['title' => 'T']);
    $actor = StringKeyTask::create(['title' => 'Actor']);
    $root = $task->pass('root');
    $root->pass(TaskStage::Review, actor: $actor);
    $task->pass(TaskStage::Review, actor: $actor, supersede: false);

    expect($task->passIfNotCurrent(TaskStage::Review, actor: $actor))->toBeFalse()
        ->and($root->passIfNotCurrent(TaskStage::Review, actor: $actor))->toBeFalse()
        ->and($task->passIfNotCurrent(TaskStage::Draft, actor: $actor))->toBeInstanceOf(Movement::class);
});

it('back-fills the actor on the same update as completed_at', function () {
    $task = Task::create(['title' => 'T']);
    $actor = Task::create(['title' => 'Actor']);
    $first = $task->pass('a');
    $updates = [];
    DB::listen(function ($query) use (&$updates) {
        if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'movements')) {
            $updates[] = $query->sql;
        }
    });

    $task->pass('b', actor: $actor);

    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toContain('actor_id')->toContain('completed_at')
        ->and($first->fresh()->actor_id)->toBe($actor->id);
});

it('does not back-fill the actor when the pass has a sender', function () {
    $task = Task::create(['title' => 'T']);
    $actor = Task::create(['title' => 'Actor']);
    $sender = Task::create(['title' => 'Sender']);
    $first = $task->pass('a');

    $task->pass('b', sender: $sender, actor: $actor);

    expect($first->fresh()->actor_id)->toBeNull()
        ->and($first->fresh()->completed_at)->not->toBeNull();
});

it('unsets the cached movement relation before Passed', function () {
    $task = Task::create(['title' => 'T']);
    $task->pass('a');
    $task->load('movement');
    $seen = null;
    Event::listen(Passed::class, function () use ($task, &$seen) {
        $seen = $task->movement->status;
    });

    $task->pass('b');

    expect($seen)->toBe('b')
        ->and($task->movement->status)->toBe('b');
});

it('returns the last-written open movement from movement()', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a', receivedAt: Carbon::parse('2025-01-02'), supersede: false);
    $second = $task->pass('b', receivedAt: Carbon::parse('2025-01-01'), supersede: false);

    expect($task->fresh()->movement->id)->toBe($second->id);
});

it('returns the open movement of any depth from movement()', function () {
    $task = Task::create(['title' => 'T']);
    $root = $task->pass('root');
    $child = $root->pass('child');
    $child->complete();

    expect($task->fresh()->movement->id)->toBe($root->id);

    $next = $root->pass('next');

    expect($task->fresh()->movement->id)->toBe($next->id);
});

it('dispatches Passed once when the write retries', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('open', receivedAt: Carbon::parse('2025-01-01 08:00'));
    $transactions = new class(app('db.transactions'))
    {
        public int $queued = 0;

        public function __construct(private DatabaseTransactionsManager $manager) {}

        public function addCallback(callable $callback): void
        {
            $this->manager->addCallback($callback);

            if (++$this->queued === 2) {
                throw new UniqueConstraintViolationException('testing', 'insert', [], new RuntimeException('unique'));
            }
        }
    };
    Event::setTransactionManagerResolver(fn () => $transactions);
    $passed = 0;
    $completed = 0;
    Event::listen(Passed::class, function () use (&$passed) {
        $passed++;
    });
    Event::listen(Completed::class, function () use (&$completed) {
        $completed++;
    });

    $next = $task->pass('next');

    expect($transactions->queued)->toBe(4)
        ->and($passed)->toBe(1)
        ->and($completed)->toBe(1)
        ->and(Movement::query()->where('status', 'next')->count())->toBe(1)
        ->and($next->position)->toBe(2)
        ->and($next->previous_id)->toBe($first->id);
});

it('retries a real unique violation and writes one row', function () {
    $task = Task::create(['title' => 'T']);
    $attempts = 0;
    Movement::creating(function (Movement $movement) use (&$attempts) {
        if (++$attempts === 1) {
            DB::table('movements')->insert([
                'movable_type' => $movement->movable_type,
                'movable_id' => $movement->movable_id,
                'position' => $movement->position,
                'status' => 'competitor',
                'properties' => '[]',
                'received_at' => '2025-01-01 08:00:00',
            ]);
        }
    });
    $passed = 0;
    Event::listen(Passed::class, function () use (&$passed) {
        $passed++;
    });

    $movement = $task->pass('a');

    expect($attempts)->toBe(2)
        ->and($passed)->toBe(1)
        ->and(Movement::query()->pluck('status')->all())->toBe(['a'])
        ->and($movement->position)->toBe(1);
});

it('throws a unique violation inside a transaction of the caller, without a retry', function () {
    $task = Task::create(['title' => 'T']);
    $attempts = 0;
    Movement::saving(function () use (&$attempts) {
        $attempts++;

        throw new UniqueConstraintViolationException('testing', 'insert', [], new RuntimeException('unique'));
    });

    expect(fn () => DB::transaction(fn () => $task->pass('a')))->toThrow(UniqueConstraintViolationException::class)
        ->and($attempts)->toBe(1);
});

it('names the event property superseded', function () {
    $task = Task::create(['title' => 'T']);
    $first = $task->pass('a');
    Event::fake([Passed::class]);

    $task->pass('b');

    Event::assertDispatched(Passed::class, fn (Passed $event) => $event->superseded?->id === $first->id
        && ! property_exists($event, 'previous'));
});

it('reads the level and the open children without a lock when passing', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite does not emit FOR UPDATE.');
    }

    $task = Task::create(['title' => 'T']);
    $root = $task->pass('root');
    $root->pass('child');
    DB::enableQueryLog();

    $root->pass('next');

    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'select'))->map(fn (string $sql) => str_replace(['"', '`'], '', $sql))->values();
    $locking = $selects->filter(fn (string $sql) => str_contains($sql, 'for update'))->values();

    expect($selects[0])->toContain('tasks')->toContain('for update')
        ->and($selects[1])->toContain('where movements.id = ?')->toContain('for update')
        ->and($selects[2])->toContain('level_key')->not->toContain('for update')
        ->and($locking->filter(fn (string $sql) => str_contains($sql, 'level_key') || str_contains($sql, 'parent_id'))->all())->toBe([]);
});
