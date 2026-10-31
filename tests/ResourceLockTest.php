<?php

use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Support\ResourceLock;
use Hasyirin\KPI\Tests\TestSupport\Models\AliasedTask;
use Hasyirin\KPI\Tests\TestSupport\Models\SoftDeletingTask;
use Hasyirin\KPI\Tests\TestSupport\Models\Task;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    Relation::morphMap([], false);
});

function uniqueViolation(): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException('testing', 'select 1', [], new Exception);
}

function deadlock(): QueryException
{
    return new QueryException('testing', 'select 1', [], new Exception('Deadlock found when trying to get lock'));
}

function recordQueries(): Collection
{
    $queries = collect();

    DB::listen(fn (QueryExecuted $query) => $queries->push($query));

    return $queries;
}

function expectLockedOn(Collection $queries, string $connection, string $table, string $keyName): void
{
    $lock = $queries->sole(fn (QueryExecuted $query) => str_contains($query->sql, $table) && str_starts_with($query->sql, 'select'));

    expect($lock->connectionName)->toBe($connection)
        ->and($lock->sql)->toContain($keyName);

    if (DB::connection($connection)->getDriverName() !== 'sqlite') {
        expect($lock->sql)->toContain('for update');
    }
}

function createSecondConnection(): void
{
    config()->set('database.connections.second', config('database.connections.testing'));

    Schema::connection('second')->create('second_tasks', function (Blueprint $table) {
        $table->id();
        $table->string('title');
    });
}

function secondConnectionResource(): Model
{
    return new class extends Model
    {
        protected $connection = 'second';

        protected $table = 'second_tasks';
    };
}

function movementFor(string $type, int $id, ?string $connection = null): Movement
{
    return (new Movement)->setConnection($connection)->forceFill([
        'movable_type' => $type,
        'movable_id' => $id,
    ]);
}

it('locks a resource through the morph map and a custom key', function () {
    Schema::create('aliased_tasks', function (Blueprint $table) {
        $table->id('uuid');
        $table->string('title');
        $table->timestamps();
    });

    Relation::morphMap(['aliased' => AliasedTask::class]);

    $task = AliasedTask::create(['title' => 'T']);

    $movement = (new Movement)->forceFill([
        'movable_type' => 'aliased',
        'movable_id' => $task->getKey(),
        'position' => 1,
        'status' => 'open',
        'received_at' => now(),
    ]);
    $movement->save();
    $movement = $movement->fresh();

    $queries = recordQueries();

    expect(ResourceLock::forMovement($movement)->run($movement->getConnection(), fn () => 'ran'))->toBe('ran');

    expectLockedOn($queries, 'testing', 'aliased_tasks', 'uuid');
    expect($queries->sole()->bindings)->toBe([$task->getKey()]);
});

it('locks a soft-deleted resource', function () {
    Schema::create('soft_deleting_tasks', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->softDeletes();
        $table->timestamps();
    });

    $task = SoftDeletingTask::create(['title' => 'T']);
    $task->delete();

    expect(ResourceLock::forResource($task)->run(DB::connection(), fn () => 'ran'))->toBe('ran');
});

it('throws when the resource row is gone', function () {
    $task = Task::create(['title' => 'T']);
    DB::table('tasks')->delete();

    $ran = false;

    expect(function () use ($task, &$ran) {
        ResourceLock::forResource($task)->run(DB::connection(), function () use (&$ran) {
            $ran = true;
        });
    })->toThrow(LogicException::class);

    expect($ran)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);
});

it('retries a unique violation when it owns the transaction', function () {
    $attempts = 0;
    $task = Task::create(['title' => 'T']);

    $result = ResourceLock::forResource($task)->run(DB::connection(), function () use (&$attempts) {
        if (++$attempts < 3) {
            throw uniqueViolation();
        }

        return 'ran';
    });

    expect($result)->toBe('ran')->and($attempts)->toBe(3);
});

it('stops after 3 attempts', function () {
    $attempts = 0;
    $task = Task::create(['title' => 'T']);

    expect(function () use ($task, &$attempts) {
        ResourceLock::forResource($task)->run(DB::connection(), function () use (&$attempts) {
            $attempts++;

            throw uniqueViolation();
        });
    })->toThrow(UniqueConstraintViolationException::class);

    expect($attempts)->toBe(3)
        ->and(DB::transactionLevel())->toBe(0);
});

it('recovers from a real unique violation', function () {
    Schema::create('unique_rows', function (Blueprint $table) {
        $table->id();
        $table->string('title')->unique();
    });
    DB::table('unique_rows')->insert(['title' => 'taken']);

    $attempts = 0;
    $task = Task::create(['title' => 'T']);

    ResourceLock::forResource($task)->run(DB::connection(), function () use (&$attempts) {
        DB::table('unique_rows')->insert(['title' => ++$attempts === 1 ? 'taken' : 'free']);
    });

    expect($attempts)->toBe(2)
        ->and(DB::table('unique_rows')->orderBy('id')->pluck('title')->all())->toBe(['taken', 'free']);
});

it('retries a concurrency error when it owns the transaction', function () {
    $attempts = 0;
    $task = Task::create(['title' => 'T']);

    $result = ResourceLock::forResource($task)->run(DB::connection(), function () use (&$attempts) {
        if (++$attempts < 3) {
            throw deadlock();
        }

        return 'ran';
    });

    expect($result)->toBe('ran')->and($attempts)->toBe(3);
});

it('does not retry inside a caller transaction', function () {
    $attempts = 0;
    $task = Task::create(['title' => 'T']);

    expect(function () use ($task, &$attempts) {
        DB::transaction(function () use ($task, &$attempts) {
            ResourceLock::forResource($task)->run(DB::connection(), function () use (&$attempts) {
                $attempts++;

                throw uniqueViolation();
            });
        });
    })->toThrow(UniqueConstraintViolationException::class);

    expect($attempts)->toBe(1);
});

it('gives the caller a DeadlockException without a retry inside a caller transaction', function () {
    $attempts = 0;
    $task = Task::create(['title' => 'T']);

    expect(function () use ($task, &$attempts) {
        DB::transaction(function () use ($task, &$attempts) {
            ResourceLock::forResource($task)->run(DB::connection(), function () use (&$attempts) {
                $attempts++;

                throw deadlock();
            });
        });
    })->toThrow(DeadlockException::class);

    expect($attempts)->toBe(1);
});

it('rolls back the failed attempt before it retries', function () {
    $attempts = 0;
    $task = Task::create(['title' => 'resource']);

    ResourceLock::forResource($task)->run(DB::connection(), function () use (&$attempts) {
        if (++$attempts === 1) {
            Task::create(['title' => 'attempt 1']);

            throw uniqueViolation();
        }

        Task::create(['title' => 'attempt 2']);
    });

    expect(Task::orderBy('id')->pluck('title')->all())->toBe(['resource', 'attempt 2']);
});

it('uses the resource class connection for a movement, and the movement connection when it sets none', function () {
    createSecondConnection();

    $id = DB::connection('second')->table('second_tasks')->insertGetId(['title' => 'T']);

    $withConnection = secondConnectionResource();
    $withoutConnection = new class extends Model
    {
        protected $table = 'second_tasks';
    };

    $queries = recordQueries();
    $movement = movementFor($withConnection::class, $id);
    ResourceLock::forMovement($movement)->run($movement->getConnection(), fn () => null);

    expect($movement->getConnection()->getName())->toBe('testing');
    expectLockedOn($queries, 'second', 'second_tasks', 'id');

    $queries->splice(0);
    $movement = movementFor($withoutConnection::class, $id, 'second');
    ResourceLock::forMovement($movement)->run($movement->getConnection(), fn () => null);

    expectLockedOn($queries, 'second', 'second_tasks', 'id');
});

it('uses the connection of the resource instance', function () {
    createSecondConnection();

    $id = DB::connection('second')->table('second_tasks')->insertGetId(['title' => 'T']);
    $resource = secondConnectionResource()->forceFill(['id' => $id]);

    $queries = recordQueries();
    ResourceLock::forResource($resource)->run(DB::connection(), fn () => null);

    expectLockedOn($queries, 'second', 'second_tasks', 'id');
});

it('wraps the movement transaction in the lock transaction on two connections', function () {
    createSecondConnection();

    $id = DB::connection('second')->table('second_tasks')->insertGetId(['title' => 'T']);
    $movement = movementFor(secondConnectionResource()::class, $id);

    $log = [];
    Event::listen(TransactionBeginning::class, function ($event) use (&$log) {
        $log[] = 'begin '.$event->connectionName;
    });
    Event::listen(TransactionCommitted::class, function ($event) use (&$log) {
        $log[] = 'commit '.$event->connectionName;
    });
    Event::listen(QueryExecuted::class, function ($event) use (&$log) {
        $log[] = 'query '.$event->connectionName;
    });

    ResourceLock::forMovement($movement)->run($movement->getConnection(), function () use (&$log) {
        $log[] = 'callback';
    });

    expect($log)->toBe([
        'begin second',
        'query second',
        'begin testing',
        'callback',
        'commit testing',
        'commit second',
    ]);
});

it('rolls back both connections before it retries', function () {
    createSecondConnection();

    $id = DB::connection('second')->table('second_tasks')->insertGetId(['title' => 'T']);
    $movement = movementFor(secondConnectionResource()::class, $id);

    $attempts = 0;

    ResourceLock::forMovement($movement)->run($movement->getConnection(), function () use (&$attempts) {
        $title = 'attempt '.++$attempts;

        Task::create(['title' => $title]);
        DB::connection('second')->table('second_tasks')->insert(['title' => $title]);

        if ($attempts === 1) {
            throw uniqueViolation();
        }
    });

    expect(Task::orderBy('id')->pluck('title')->all())->toBe(['attempt 2'])
        ->and(DB::connection('second')->table('second_tasks')->orderBy('id')->pluck('title')->all())->toBe(['T', 'attempt 2'])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection('second')->transactionLevel())->toBe(0);
});

it('does not retry when the caller holds a transaction on either connection', function (string $callerConnection) {
    createSecondConnection();

    $id = DB::connection('second')->table('second_tasks')->insertGetId(['title' => 'T']);
    $movement = movementFor(secondConnectionResource()::class, $id);

    $attempts = 0;

    expect(function () use ($callerConnection, $movement, &$attempts) {
        DB::connection($callerConnection)->transaction(function () use ($movement, &$attempts) {
            ResourceLock::forMovement($movement)->run($movement->getConnection(), function () use (&$attempts) {
                $attempts++;

                throw uniqueViolation();
            });
        });
    })->toThrow(UniqueConstraintViolationException::class);

    expect($attempts)->toBe(1);
})->with(['lock connection' => 'second', 'movement connection' => 'testing']);
