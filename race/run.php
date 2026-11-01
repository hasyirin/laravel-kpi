<?php

declare(strict_types=1);

use Hasyirin\KPI\Tests\TestSupport\Models\Task;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/bootstrap.php';

$driver = $argv[1] ?? '';
if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
    fwrite(STDERR, "usage: php8.4 race/run.php <pgsql|mysql|mariadb> [scenario-prefix]\n");
    exit(2);
}
foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'] as $required) {
    if (getenv($required) === false) {
        fwrite(STDERR, "$required must be set: the harness drops and creates its tables on that database.\n");
        exit(2);
    }
}
putenv("DB_CONNECTION=$driver");

const RUNS = 5;
const TASK_TYPE = Task::class;

raceBoot($driver);

set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'harness error: '.$e->getMessage()."\n");
    exit(1);
});

$snapshotIsolation = $driver === 'mariadb' && (bool) DB::selectOne('select @@innodb_snapshot_isolation as v')->v;
echo 'server: '.DB::selectOne('select version() as v')->v.($driver === 'mariadb' ? ' snapshot_isolation='.($snapshotIsolation ? 'on' : 'off') : '')."\n";

foreach (['movements', 'holidays', 'recurring_holidays', 'tasks', 'race_resources'] as $table) {
    Schema::dropIfExists($table);
}
foreach (['create_holidays_table', 'create_movements_table', 'create_recurring_holidays_table'] as $migration) {
    (include __DIR__."/../database/migrations/$migration.php.stub")->up();
}
foreach (['tasks', 'race_resources'] as $table) {
    Schema::create($table, function ($blueprint) {
        $blueprint->id();
        $blueprint->string('title');
        $blueprint->timestamps();
    });
}

final class RaceFailure extends RuntimeException {}

final class Race
{
    public string $dir;

    /** @var list<array{process: resource, role: string, started: float}> */
    private array $workers = [];

    public int $lockWaits = 0;

    private float $holderReleasedAt = INF;

    public function __construct(private readonly string $driver)
    {
        $this->dir = sys_get_temp_dir().'/kpi-race-'.getmypid().'-'.uniqid();
        mkdir($this->dir);
    }

    public function __destruct()
    {
        foreach ($this->workers as $worker) {
            proc_terminate($worker['process'], SIGKILL);
            proc_close($worker['process']);
        }
        array_map('unlink', glob("$this->dir/*") ?: []);
        rmdir($this->dir);
    }

    public function spawn(string $role, array $spec): void
    {
        $spec += ['role' => $role, 'dir' => $this->dir];
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/worker.php', json_encode($spec, JSON_THROW_ON_ERROR)],
            [1 => ['file', "$this->dir/$role.out", 'w'], 2 => ['file', "$this->dir/$role.out", 'a']],
            $pipes,
        );
        $this->workers[] = ['process' => $process, 'role' => $role, 'started' => microtime(true)];
    }

    /** @return array<string, array<string, mixed>> */
    public function join(int $timeoutSeconds = 70): array
    {
        $logs = [];
        foreach ($this->workers as $key => $worker) {
            while (proc_get_status($worker['process'])['running']) {
                if (microtime(true) - $worker['started'] > $timeoutSeconds) {
                    proc_terminate($worker['process'], SIGKILL);
                    throw new RaceFailure("worker {$worker['role']} hung");
                }
                usleep(5000);
            }
            proc_close($worker['process']);
            unset($this->workers[$key]);
            $file = "$this->dir/{$worker['role']}.log.json";
            if (! is_file($file)) {
                throw new RaceFailure("worker {$worker['role']} crashed: ".@file_get_contents("$this->dir/{$worker['role']}.out"));
            }
            $logs[$worker['role']] = json_decode(file_get_contents($file), true);
        }

        foreach ($logs as $role => $log) {
            check($log['barrier_timeouts'] === [], "$role barrier timeout: ".implode(',', $log['barrier_timeouts']));
        }

        return $logs;
    }

    public function awaitFile(string $name, int $timeoutMs = 30000): void
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (! file_exists("$this->dir/$name")) {
            check(microtime(true) < $deadline, "no overlap: never saw barrier $name");
            usleep(2000);
        }
    }

    public function release(string $name): void
    {
        touch("$this->dir/$name");
    }

    public function releaseHolder(): void
    {
        $this->holderReleasedAt = microtime(true);
        $this->release('go');
    }

    public function checkWaitedOnResourceLock(array $waiter): void
    {
        check(($waiter['locks'][0]['at'] ?? 0) > $this->holderReleasedAt, "{$waiter['role']} did not wait on the resource row: its resource lock was not acquired after the holder was released");
    }

    public function awaitServerLockWait(int $timeoutMs = 10000): void
    {
        $waiting = $this->driver === 'pgsql'
            ? fn () => (int) DB::selectOne("select count(*) as c from pg_stat_activity where wait_event_type = 'Lock' and datname = current_database()")->c
            : fn () => (int) DB::selectOne("show global status like 'Innodb_row_lock_current_waits'")->Value;
        $deadline = microtime(true) + $timeoutMs / 1000;
        while ($waiting() < 1) {
            check(microtime(true) < $deadline, 'no overlap: the server never showed a session waiting on a lock');
            usleep(2000);
        }
        $this->lockWaits++;
    }

    public function assertResourceLocked(string $table, int $id): void
    {
        $nowait = "select id from $table where id = ? for update nowait";
        DB::beginTransaction();
        try {
            DB::select($nowait, [$id]);
        } catch (QueryException $e) {
            $lockError = $this->driver === 'pgsql' ? $e->errorInfo[0] === '55P03' : in_array($e->errorInfo[1], [1205, 3572], true);
            check($lockError, "resource lock probe failed with another error: {$e->getMessage()}");

            return;
        } finally {
            DB::rollBack();
        }
        check(false, "resource row $table.$id was not locked while the transaction was open");
    }

    /** @return array<string, mixed> */
    public function holdUntilBlocked(array $holder, array $waiter): array
    {
        $holder['hooks'] = array_replace($holder['hooks'] ?? [], [
            $holder['pause_at'] => [['arrive', 'held'], ['wait', 'go', 30000]],
        ]);
        unset($holder['pause_at']);
        $this->spawn('a', $holder);
        $this->awaitFile('held');
        $this->spawn('b', $waiter);
        $this->awaitServerLockWait();
        $this->releaseHolder();
        $logs = $this->join();
        $this->checkWaitedOnResourceLock($logs['b']);

        return $logs;
    }
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RaceFailure($message);
    }
}

function checkNoRetries(array $logs): void
{
    check(retries($logs['a']) + retries($logs['b']) === 0, 'a retry happened: '.retries($logs['a']).'+'.retries($logs['b']));
}

function checkOverlap(array $a, array $b): void
{
    check(max($a['tx_begin'], $b['tx_begin']) < min($a['tx_end'], $b['tx_end']), 'no overlap: the two transactions did not run at the same time');
}

function newTask(string $title = 't'): int
{
    return (int) DB::table('tasks')->insertGetId(['title' => $title]);
}

function seed(int $taskId, int $position, array $attributes = []): int
{
    return (int) DB::table('movements')->insertGetId(row($taskId, $position, $attributes));
}

function row(int $taskId, int $position, array $attributes = []): array
{
    return $attributes + [
        'movable_type' => TASK_TYPE,
        'movable_id' => $taskId,
        'position' => $position,
        'status' => 'seed',
        'properties' => '[]',
        'expects_children' => false,
        'received_at' => now()->subHour()->format('Y-m-d H:i:s'),
    ];
}

function closed(): array
{
    return ['completed_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'seconds' => 0, 'period' => 0];
}

/** @return list<object> */
function level(int $taskId, ?int $parentId = null): array
{
    return DB::table('movements')
        ->where('movable_id', $taskId)
        ->where('parent_id', $parentId)
        ->orderBy('position')
        ->get()
        ->all();
}

function checkChain(array $rows, int $expected, array $linkless = []): void
{
    check(count($rows) === $expected, "expected $expected rows on the level, found ".count($rows));
    $previous = null;
    foreach ($rows as $index => $row) {
        check((int) $row->position === $index + 1, "row $row->id has position $row->position, expected ".($index + 1));
        if (! in_array((int) $row->id, $linkless, true)) {
            check($row->previous_id === null ? $previous === null : (int) $row->previous_id === $previous, "row $row->id links to ".var_export($row->previous_id, true).', expected '.var_export($previous, true));
        }
        $previous = (int) $row->id;
    }
}

function retries(array $log): int
{
    return ($log['begins'] === [] ? $log['calls'] : max($log['begins'])) - $log['calls'];
}

function checkEvents(array $log, int $passed, int $completed): void
{
    check(count($log['passed']) === $passed, "{$log['role']} dispatched ".count($log['passed'])." Passed, expected $passed");
    check(count($log['completed']) === $completed, "{$log['role']} dispatched ".count($log['completed'])." Completed, expected $completed");
}

function checkNoException(array $log): void
{
    if ($log['exception'] !== null) {
        throw new RaceFailure("{$log['role']} threw {$log['exception']['class']}: {$log['exception']['message']}");
    }
}

function errorTag(?array $exception): string
{
    if ($exception === null) {
        return 'none';
    }
    preg_match('/\b(1020|1062|1205|1213|23505|40001|40P01|55P03)\b/', $exception['message'], $code);

    return class_basename($exception['class']).':'.($code[1] ?? 'other');
}

function insertPause(string $name): array
{
    return [['arrive', "$name-in"], ['wait', "$name-out", 20000]];
}

function twoResourceBarriers(string $me, string $other): array
{
    $step = fn (int $n) => [['arrive', "$me$n"], ['wait', "$other$n", 5000]];

    return ['update' => $step(1), 'insert' => $step(2), 'created' => $step(3)];
}

$scenarios = [
    'complete-then-pass' => function (Race $race) {
        $task = newTask();
        $open = seed($task, 1);
        $logs = $race->holdUntilBlocked(
            ['action' => 'movement.complete', 'movement' => $open, 'pause_at' => 'update'],
            ['action' => 'resource.pass', 'task' => $task, 'status' => 'next'],
        );
        checkOverlap($logs['a'], $logs['b']);
        checkNoException($logs['a']);
        checkNoException($logs['b']);
        checkNoRetries($logs);
        checkEvents($logs['a'], 0, 1);
        checkEvents($logs['b'], 1, 0);
        check($logs['b']['passed'][0]['superseded'] === null, 'pass superseded a row that was already closed');
        checkChain(level($task), 2);
        check(level($task)[0]->completed_at !== null, 'target not closed');

        return [$logs];
    },
    'pass-then-complete' => function (Race $race) {
        $task = newTask();
        $open = seed($task, 1);
        $logs = $race->holdUntilBlocked(
            ['action' => 'resource.pass', 'task' => $task, 'status' => 'next', 'pause_at' => 'insert'],
            ['action' => 'movement.complete', 'movement' => $open],
        );
        checkOverlap($logs['a'], $logs['b']);
        checkNoException($logs['a']);
        checkNoException($logs['b']);
        checkNoRetries($logs);
        checkEvents($logs['a'], 1, 1);
        checkEvents($logs['b'], 0, 0);
        check($logs['b']['return'] === $logs['a']['completed'][0]['completed_at'], 'complete() changed or did not read the stored completed_at');
        $rows = level($task);
        checkChain($rows, 2);
        check($rows[0]->completed_at !== null && $rows[1]->completed_at === null, 'expected closed target and open new row');

        return [$logs];
    },
    'pass-on-stale-completed' => function (Race $race) {
        $task = newTask();
        $open = seed($task, 1);
        $race->spawn('b', ['action' => 'movement.pass', 'movement' => $open, 'status' => 'child', 'before' => [['arrive', 'loaded'], ['wait', 'held']]]);
        $race->awaitFile('loaded');
        $race->spawn('a', ['action' => 'movement.complete', 'movement' => $open, 'hooks' => ['update' => [['arrive', 'held'], ['wait', 'go', 30000]]]]);
        $race->awaitServerLockWait();
        $race->releaseHolder();
        $logs = $race->join();
        $race->checkWaitedOnResourceLock($logs['b']);
        checkOverlap($logs['a'], $logs['b']);
        check(($logs['b']['exception']['class'] ?? null) === LogicException::class, 'expected LogicException, got '.errorTag($logs['b']['exception']));
        checkEvents($logs['a'], 0, 1);
        checkEvents($logs['b'], 0, 0);
        check(DB::table('movements')->count() === 1, 'a new row was written');

        return [$logs];
    },
    'two-first-passes-one-resource' => function (Race $race) {
        $task = newTask();
        $logs = $race->holdUntilBlocked(
            ['action' => 'resource.pass', 'task' => $task, 'status' => 'a', 'pause_at' => 'insert'],
            ['action' => 'resource.pass', 'task' => $task, 'status' => 'b'],
        );
        checkOverlap($logs['a'], $logs['b']);
        checkNoException($logs['a']);
        checkNoException($logs['b']);
        checkNoRetries($logs);
        checkEvents($logs['a'], 1, 0);
        checkEvents($logs['b'], 1, 1);
        checkChain(level($task), 2);

        return [$logs];
    },
    'two-first-passes-two-resources' => function (Race $race) {
        [$one, $two] = [newTask(), newTask()];
        $race->spawn('a', ['action' => 'resource.pass', 'task' => $one, 'status' => 'a', 'hooks' => twoResourceBarriers('a', 'b')]);
        $race->spawn('b', ['action' => 'resource.pass', 'task' => $two, 'status' => 'b', 'hooks' => twoResourceBarriers('b', 'a')]);
        $logs = $race->join();
        checkOverlap($logs['a'], $logs['b']);
        checkNoException($logs['a']);
        checkNoException($logs['b']);
        checkNoRetries($logs);
        checkChain(level($one), 1);
        checkChain(level($two), 1);

        return [$logs];
    },
    'two-ordinary-passes-two-resources' => function (Race $race) {
        [$one, $two] = [newTask(), newTask()];
        seed($one, 1);
        seed($two, 1);
        $race->spawn('a', ['action' => 'resource.pass', 'task' => $one, 'status' => 'a', 'hooks' => twoResourceBarriers('a', 'b')]);
        $race->spawn('b', ['action' => 'resource.pass', 'task' => $two, 'status' => 'b', 'hooks' => twoResourceBarriers('b', 'a')]);
        $logs = $race->join();
        checkOverlap($logs['a'], $logs['b']);
        checkNoException($logs['a']);
        checkNoException($logs['b']);
        checkNoRetries($logs);
        checkChain(level($one), 2);
        checkChain(level($two), 2);

        return [$logs];
    },
    'two-passIfNotCurrent-one-status' => function (Race $race) {
        $task = newTask();
        seed($task, 1, ['status' => 'old']);
        $logs = $race->holdUntilBlocked(
            ['action' => 'resource.passIfNotCurrent', 'task' => $task, 'status' => 'review', 'pause_at' => 'insert'],
            ['action' => 'resource.passIfNotCurrent', 'task' => $task, 'status' => 'review'],
        );
        checkOverlap($logs['a'], $logs['b']);
        checkNoException($logs['a']);
        checkNoException($logs['b']);
        checkNoRetries($logs);
        check(is_int($logs['a']['return']) && $logs['b']['return'] === false, 'expected one new row and one false');
        checkEvents($logs['b'], 0, 0);
        checkChain(level($task), 2);

        return [$logs];
    },
    'stale-caller-first-movement' => fn (Race $race) => staleCaller($race, [], $driver, $snapshotIsolation),
    'stale-caller-open-tail' => fn (Race $race) => staleCaller($race, [[]], $driver, $snapshotIsolation),
    'stale-caller-closed-tail' => fn (Race $race) => staleCaller($race, [closed()], $driver, $snapshotIsolation),
    'retry-after-unique-violation' => function (Race $race) {
        $task = newTask();
        $open = seed($task, 1);
        $race->spawn('a', ['action' => 'resource.pass', 'task' => $task, 'status' => 'a', 'hooks' => ['insert' => insertPause('a')]]);
        $race->spawn('b', ['action' => 'rogue.insert', 'row' => row($task, 2, closed() + ['status' => 'rogue']), 'before' => [['wait', 'a-in']], 'inside' => [['arrive', 'a-out']]]);
        $logs = $race->join();
        checkRetried($logs, $open);
        $rows = level($task);
        check(count($rows) === 3, 'expected seed, rogue and one new row, found '.count($rows));
        checkChain($rows, 3, [(int) $rows[1]->id]);
        check($rows[2]->status === 'a' && (int) $rows[2]->previous_id === (int) $rows[1]->id, 'new row does not link to the rogue row');
        check($rows[0]->completed_at !== null, 'sibling not closed');

        return [$logs];
    },
    'retry-model-path-with-sibling' => function (Race $race) {
        $task = newTask();
        $parent = seed($task, 1, ['expects_children' => true]);
        $sibling = seed($task, 1, ['parent_id' => $parent]);
        $race->spawn('a', ['action' => 'movement.pass', 'movement' => $parent, 'status' => 'a', 'hooks' => ['insert' => insertPause('a')]]);
        $race->spawn('b', ['action' => 'rogue.insert', 'row' => row($task, 2, closed() + ['status' => 'rogue', 'parent_id' => $parent]), 'before' => [['wait', 'a-in']], 'inside' => [['arrive', 'a-out']]]);
        $logs = $race->join();
        checkRetried($logs, $sibling);
        $children = level($task, $parent);
        check(count($children) === 3, 'expected sibling, rogue and one new child, found '.count($children));
        check($children[2]->status === 'a' && (int) $children[2]->previous_id === (int) $children[1]->id, 'new child does not link to the latest child');
        check($children[0]->completed_at !== null, 'sibling not closed');
        $instance = $logs['a']['instance'];
        check($instance['dirty'] === [] && $instance['relations'] === [], 'parent instance is dirty or kept relations');
        check($instance['attributes'] == $instance['fresh'], 'parent instance differs from its row');
        check($instance['fresh']['completed_at'] === null, 'parent was closed');

        return [$logs];
    },
    'retry-two-connections' => function (Race $race) {
        $resource = (int) DB::table('race_resources')->insertGetId(['title' => 't']);
        $open = (int) DB::table('movements')->insertGetId(row($resource, 1, ['movable_type' => RaceResource::class]));
        $race->spawn('a', ['action' => 'resource.pass', 'raceResource' => $resource, 'status' => 'a', 'movement_class' => RaceMovement::class, 'hooks' => ['insert' => insertPause('a')]]);
        $race->awaitFile('a-in');
        $race->assertResourceLocked('race_resources', $resource);
        $race->spawn('b', ['action' => 'rogue.insert', 'row' => row($resource, 2, closed() + ['status' => 'rogue', 'movable_type' => RaceResource::class]), 'inside' => [['arrive', 'a-out']]]);
        $logs = $race->join();
        checkRetried($logs, $open);
        $connections = array_unique(array_column($logs['a']['locks'], 'connection'));
        check($connections === [RACE_RESOURCE_CONNECTION], 'lock ran on '.implode(',', $connections));
        check(array_keys($logs['a']['tables_by_connection']) === [RACE_MOVEMENT_CONNECTION], 'movement queries ran on '.implode(',', array_keys($logs['a']['tables_by_connection'])));
        $rows = DB::table('movements')->where('movable_type', RaceResource::class)->where('movable_id', $resource)->orderBy('position')->get();
        check($rows->count() === 3 && $rows[2]->status === 'a' && (int) $rows[2]->previous_id === (int) $rows[1]->id && $rows[0]->completed_at !== null, 'unexpected rows after the retry');

        return [$logs];
    },
    'lock-timeout-at-resource-lock' => function (Race $race) use ($driver) {
        $task = newTask();
        seed($task, 1);
        $race->spawn('a', ['action' => 'hold.resource', 'task' => $task, 'inside' => [['arrive', 'held'], ['wait', 'done', 30000]]]);
        $race->awaitFile('held');
        $race->spawn('b', ['action' => 'caller.pass', 'task' => $task, 'status' => 'b', 'short_lock_timeout' => true]);
        $race->awaitServerLockWait();
        $logs = timeoutOutcome($race, $task, $driver, 'for update');

        return [$logs];
    },
    'lock-timeout-after-close' => function (Race $race) use ($driver) {
        $task = newTask();
        seed($task, 1);
        $race->spawn('a', ['action' => 'rogue.insert', 'row' => row($task, 2, ['status' => 'rogue']), 'commit' => false, 'inside' => [['arrive', 'held'], ['wait', 'done', 30000]]]);
        $race->awaitFile('held');
        $race->spawn('b', ['action' => 'caller.pass', 'task' => $task, 'status' => 'b', 'short_lock_timeout' => true]);
        $race->awaitServerLockWait();
        $logs = timeoutOutcome($race, $task, $driver, 'insert into');

        return [$logs];
    },
    'backdated-then-normal-versus-pass' => function (Race $race) {
        $task = newTask();
        seed($task, 1);
        $logs = $race->holdUntilBlocked(
            ['action' => 'backdated.then.normal', 'task' => $task, 'status' => 'a', 'calls' => 2, 'pause_at' => 'insert'],
            ['action' => 'resource.pass', 'task' => $task, 'status' => 'b'],
        );
        checkOverlap($logs['a'], $logs['b']);
        checkNoException($logs['a']);
        checkNoException($logs['b']);
        $rows = level($task);
        checkChain($rows, 4);
        check(collect($rows)->whereNull('completed_at')->count() === 1, 'expected one open movement');

        return [$logs];
    },
    'hard-deletes-then-pass' => function (Race $race) {
        [$chain, $tree] = [newTask(), newTask()];
        $race->spawn('a', ['action' => 'hard.delete', 'task' => $chain, 'tree' => $tree, 'calls' => 10]);
        $logs = $race->join();
        checkNoException($logs['a']);
        $rows = level($chain);
        check(array_map(fn ($row) => (int) $row->position, $rows) === [2, 4, 5], 'chain positions after deletes: '.json_encode(array_column($rows, 'position')));
        check($rows[0]->previous_id === null && $rows[1]->previous_id === null, 'deleted links must be null');
        check((int) $rows[2]->id === $logs['a']['return']['after_head_and_mid'] && (int) $rows[2]->previous_id === (int) $rows[1]->id, 'pass after deletes did not link to the latest row');
        $treeRows = DB::table('movements')->where('movable_id', $tree)->get();
        check($treeRows->count() === 1 && (int) $treeRows[0]->id === $logs['a']['return']['after_parent'] && (int) $treeRows[0]->position === 1 && $treeRows[0]->previous_id === null, 'parent delete did not cascade, or the next pass is wrong');
        check($logs['a']['return']['deleted_parent_error'] !== null, 'pass from a hard-deleted movement did not throw LogicException');

        return [$logs];
    },
];

function staleCaller(Race $race, array $seeds, string $driver, bool $snapshotIsolation): array
{
    $task = newTask();
    foreach ($seeds as $index => $attributes) {
        seed($task, $index + 1, $attributes);
    }
    $race->spawn('a', ['action' => 'caller.pass', 'task' => $task, 'status' => 'a', 'read_first' => true, 'inside' => [['arrive', 'read'], ['wait', 'b-done']]]);
    $race->spawn('b', ['action' => 'resource.pass', 'task' => $task, 'status' => 'b', 'before' => [['wait', 'read']]]);
    $race->awaitFile('read');
    $race->awaitFile('b.log.json');
    $race->release('b-done');
    $logs = $race->join();
    check($logs['a']['tx_begin'] < $logs['b']['tx_end'] && $logs['b']['tx_end'] < $logs['a']['tx_end'], 'no overlap: the caller transaction did not span the other commit');
    checkNoException($logs['b']);
    $rows = level($task);

    // READ COMMITTED reads fresh rows per statement, so PostgreSQL has no stale read to reject (spec 4.3).
    if ($driver === 'pgsql') {
        checkNoException($logs['a']);
        checkChain($rows, count($seeds) + 2);
        check((int) $rows[count($rows) - 1]->id === $logs['a']['return'], 'the caller row is not the latest on the level');
        check(collect($rows)->whereNull('completed_at')->count() === 1, 'expected one open movement');
        checkEvents($logs['a'], 1, 1);
        check($logs['a']['passed'][0]['superseded'] === $logs['b']['return'], 'caller did not supersede the row written by the other worker');
    } else {
        $allowed = ['UniqueConstraintViolationException:1062'];
        if ($snapshotIsolation) {
            $allowed[] = 'DeadlockException:1020';
        }
        $tag = errorTag($logs['a']['exception']);
        check(in_array($tag, $allowed, true), "expected a unique violation (or 1020), got $tag");
        checkEvents($logs['a'], 0, 0);
        checkChain($rows, count($seeds) + 1);
    }

    return [$logs];
}

function checkRetried(array $logs, int $closedRow): void
{
    checkNoException($logs['a']);
    checkNoException($logs['b']);
    check(retries($logs['a']) >= 1, 'no retry happened, so the scenario proved nothing');
    check($logs['a']['locks'][0]['at'] < $logs['b']['ended'] && $logs['b']['ended'] < $logs['a']['locks'][1]['at'], 'no overlap: the conflicting insert did not commit between the two attempts');
    checkEvents($logs['a'], 1, 1);
    check($logs['a']['completed'][0]['id'] === $closedRow, 'wrong row completed');
    check($logs['a']['passed'][0]['superseded'] === $closedRow, 'Passed has the wrong superseded row');
    check($logs['a']['passed'][0]['current'] === $logs['a']['return'], 'Passed has the wrong current row');
}

function timeoutOutcome(Race $race, int $task, string $driver, string $blockedStatement): array
{
    $race->awaitFile('b.log.json');
    $race->release('done');
    $logs = $race->join();
    checkEvents($logs['b'], 0, 0);
    check($logs['b']['ended'] < $logs['a']['ended'], 'no overlap: the holder ended before the timeout');
    $exception = $logs['b']['exception'];
    check($exception !== null, 'caller did not get an error');
    check(str_contains($exception['message'], $blockedStatement), "timeout did not hit the '$blockedStatement' statement: {$exception['message']}");
    // Laravel does not class PostgreSQL 55P03 as a concurrency error. Spec 4.3 names only 40001 on PostgreSQL.
    $expected = $driver === 'pgsql' ? QueryException::class : DeadlockException::class;
    check($exception['class'] === $expected, "expected $expected, got {$exception['class']}: {$exception['message']}");
    $rows = level($task);
    check(count($rows) === 1 && $rows[0]->completed_at === null, 'target did not stay open after the rollback: task '.$task.' '.json_encode($rows));

    return $logs;
}

$only = $argv[2] ?? '';
$failed = false;
$ran = 0;

foreach ($scenarios as $name => $scenario) {
    if ($only !== '' && ! str_starts_with($name, $only)) {
        continue;
    }

    $ran++;
    $passed = 0;
    $retries = 0;
    $lockWaits = 0;
    $errors = [];
    $failures = [];

    for ($run = 1; $run <= RUNS; $run++) {
        DB::table('movements')->delete();
        DB::table('tasks')->delete();
        DB::table('race_resources')->delete();

        $race = new Race($driver);
        try {
            [$logs] = $scenario($race);
            $passed++;
            foreach ($logs as $log) {
                $retries += retries($log);
                if ($log['exception'] !== null) {
                    $tag = errorTag($log['exception']);
                    $errors[$tag] = ($errors[$tag] ?? 0) + 1;
                }
            }
        } catch (Throwable $failure) {
            $failures[] = "run $run: ".$failure->getMessage().' rows='.json_encode(DB::table('movements')->orderBy('id')->get(['id', 'parent_id', 'previous_id', 'movable_id', 'position', 'status', 'completed_at']));
            if (count($failures) === 1) {
                array_map(fn ($file) => fwrite(STDERR, basename($file).': '.file_get_contents($file)."\n"), glob("$race->dir/*.{json,out}", GLOB_BRACE) ?: []);
            }
        }
        $lockWaits += $race->lockWaits;
        unset($race);
    }

    $line = sprintf('%s %s passed=%d/%d retries=%d lock_waits=%d errors=%s', $failures === [] ? 'PASS' : 'FAIL', $name, $passed, RUNS, $retries, $lockWaits, json_encode($errors));
    echo $line."\n";
    foreach ($failures as $failure) {
        echo "  $failure\n";
    }
    $failed = $failed || $failures !== [];
}

if ($ran === 0) {
    fwrite(STDERR, "no scenario matches prefix '$only'\n");
    exit(1);
}

exit($failed ? 1 : 0);
