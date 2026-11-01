<?php

declare(strict_types=1);

use Carbon\Carbon;
use Hasyirin\KPI\Events\Completed;
use Hasyirin\KPI\Events\Passed;
use Hasyirin\KPI\Models\Movement;
use Hasyirin\KPI\Tests\TestSupport\Models\Task;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/bootstrap.php';

/** @var array<string, mixed> $spec */
$spec = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$dir = $spec['dir'];
$log = [
    'role' => $spec['role'],
    'calls' => $spec['calls'] ?? 1,
    'begins' => [],
    'locks' => [],
    'tables_by_connection' => [],
    'passed' => [],
    'completed' => [],
    'barrier_timeouts' => [],
    'return' => null,
    'tx_begin' => null,
    'tx_end' => null,
    'exception' => null,
];

raceBoot(getenv('DB_CONNECTION'));
config()->set('kpi.models.movement', $spec['movement_class'] ?? Movement::class);

$arrive = function (string $name) use ($dir): void {
    touch("$dir/$name");
};
$wait = function (string $name, int $timeoutMs = 20000) use ($dir, &$log): void {
    $deadline = microtime(true) + $timeoutMs / 1000;
    while (! file_exists("$dir/$name")) {
        if (microtime(true) > $deadline) {
            $log['barrier_timeouts'][] = $name;

            return;
        }
        usleep(2000);
    }
};
$runOps = function (array $ops) use ($arrive, $wait): void {
    foreach ($ops as $op) {
        $op[0] === 'arrive' ? $arrive($op[1]) : $wait($op[1], $op[2] ?? 20000);
    }
};

Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$log) {
    $log['tx_begin'] ??= microtime(true);
    if ($event->connection->transactionLevel() === 1) {
        $name = $event->connectionName;
        $log['begins'][$name] = ($log['begins'][$name] ?? 0) + 1;
    }
});
$endTransaction = function () use (&$log) {
    $log['tx_end'] = microtime(true);
};
Event::listen(TransactionCommitted::class, $endTransaction);
Event::listen(TransactionRolledBack::class, $endTransaction);
Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$log) {
    $sql = str_replace(['"', '`'], '', $query->sql);
    if (preg_match('/^select .* from (tasks|race_resources) .*for update/', $sql)) {
        $log['locks'][] = ['connection' => $query->connectionName, 'at' => microtime(true)];
    }
    if (preg_match('/(?:from|into|update) movements/', $sql)) {
        $log['tables_by_connection'][$query->connectionName] = true;
    }
});
Event::listen(Passed::class, function (Passed $event) use (&$log) {
    $log['passed'][] = ['current' => $event->current->getKey(), 'superseded' => $event->superseded?->getKey()];
});
Event::listen(Completed::class, function (Completed $event) use (&$log) {
    $log['completed'][] = ['id' => $event->movement->getKey(), 'completed_at' => (string) $event->movement->completed_at];
});

$hooks = $spec['hooks'] ?? [];
$movementClass = config('kpi.models.movement');
$movementClass::saving(function (Movement $movement) use ($hooks, $runOps) {
    $runOps($hooks[$movement->exists ? 'update' : 'insert'] ?? []);
});
$movementClass::created(function () use ($hooks, $runOps) {
    $runOps($hooks['created'] ?? []);
});

$resource = fn (): Task|RaceResource => isset($spec['raceResource'])
    ? RaceResource::findOrFail($spec['raceResource'])
    : Task::findOrFail($spec['task']);

$actions = [
    'resource.pass' => function () use ($spec, $resource, $runOps) {
        $model = $resource();
        $runOps($spec['before'] ?? []);

        return $model->pass($spec['status'])->getKey();
    },
    'resource.passIfNotCurrent' => function () use ($spec, $resource) {
        $movement = $resource()->passIfNotCurrent($spec['status']);

        return $movement === false ? false : $movement->getKey();
    },
    'movement.complete' => function () use ($spec) {
        return (string) Movement::findOrFail($spec['movement'])->complete()->completed_at;
    },
    'movement.pass' => function () use ($spec, $runOps, &$log) {
        $movement = Movement::findOrFail($spec['movement']);
        $runOps($spec['before'] ?? []);

        try {
            return $movement->pass($spec['status'])->getKey();
        } finally {
            $log['instance'] = [
                'attributes' => $movement->getAttributes(),
                'fresh' => (array) DB::table('movements')->find($movement->getKey()),
                'dirty' => $movement->getDirty(),
                'relations' => array_keys($movement->getRelations()),
            ];
        }
    },
    'caller.pass' => function () use ($spec, $resource, $runOps) {
        $model = $resource();

        if ($spec['short_lock_timeout'] ?? false) {
            DB::statement(raceLockTimeout());
        }

        return DB::transaction(function () use ($spec, $model, $runOps) {
            if ($spec['read_first'] ?? false) {
                DB::table('holidays')->count();
            }
            $runOps($spec['inside'] ?? []);

            return $model->pass($spec['status'])->getKey();
        });
    },
    'backdated.then.normal' => function () use ($spec, $resource) {
        $model = $resource();

        return [
            $model->pass($spec['status'].'-backdated', receivedAt: Carbon::now()->subHours(3))->getKey(),
            $model->pass($spec['status'].'-normal')->getKey(),
        ];
    },
    'hold.resource' => function () use ($spec, $runOps) {
        DB::beginTransaction();
        DB::table('tasks')->where('id', $spec['task'])->lockForUpdate()->value('id');
        $runOps($spec['inside']);
        DB::rollBack();
    },
    'rogue.insert' => function () use ($spec, $runOps) {
        $connection = DB::connection();
        $connection->statement(raceDisableForeignKeys());
        $runOps($spec['before'] ?? []);

        $commit = $spec['commit'] ?? true;
        $commit ?: $connection->beginTransaction();
        $connection->table('movements')->insert($spec['row']);
        $runOps($spec['inside'] ?? []);
        $commit ?: $connection->rollBack();
    },
    'hard.delete' => function () use ($spec) {
        $task = Task::findOrFail($spec['task']);
        $chain = collect(range(1, 4))->map(fn (int $n) => $task->pass("chain-$n"));
        $chain[0]->delete();
        $chain[2]->delete();
        $afterHeadAndMid = $task->pass('after-head-and-mid');

        $tree = Task::findOrFail($spec['tree']);
        $parent = $tree->pass('parent', expectsChildren: true);
        $parent->pass('child-1');
        $parent->pass('child-2');
        $stale = Movement::findOrFail($parent->getKey());
        $parent->delete();

        $deletedParent = null;
        try {
            $stale->pass('from-deleted');
        } catch (LogicException $e) {
            $deletedParent = $e->getMessage();
        }

        return [
            'after_head_and_mid' => $afterHeadAndMid->getKey(),
            'after_parent' => $tree->pass('after-parent')->getKey(),
            'deleted_parent_error' => $deletedParent,
        ];
    },
];

try {
    $log['return'] = $actions[$spec['action']]();
} catch (Throwable $e) {
    $log['exception'] = [
        'class' => $e::class,
        'code' => $e->getCode(),
        'message' => substr($e->getMessage(), 0, 300),
    ];
}

$log['ended'] = microtime(true);

file_put_contents("$dir/{$spec['role']}.log.json", json_encode($log, JSON_THROW_ON_ERROR));
