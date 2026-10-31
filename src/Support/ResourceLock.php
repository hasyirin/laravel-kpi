<?php

declare(strict_types=1);

namespace Hasyirin\KPI\Support;

use Closure;
use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

final class ResourceLock
{
    private function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
        private readonly string $keyName,
        private readonly mixed $key,
    ) {}

    public static function forResource(Model $resource): self
    {
        return new self($resource->getConnection(), $resource->getTable(), $resource->getKeyName(), $resource->getKey());
    }

    public static function forMovement(Movement $movement): self
    {
        /** @var class-string<Model> $class */
        $class = Relation::getMorphedModel($movement->movable_type) ?? $movement->movable_type;
        $resource = new $class;

        return new self(
            $resource->getConnectionName() ? $resource->getConnection() : $movement->getConnection(),
            $resource->getTable(),
            $resource->getKeyName(),
            $movement->movable_id,
        );
    }

    public function run(ConnectionInterface $movementConnection, Closure $callback): mixed
    {
        $lock = $this->connection;
        $owned = $lock->transactionLevel() === 0 && $movementConnection->transactionLevel() === 0;
        $maxAttempts = $owned ? 3 : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $lock->transaction(function () use ($lock, $movementConnection, $callback) {
                    $this->lockRow();

                    return $lock === $movementConnection ? $callback() : $movementConnection->transaction($callback);
                }, $maxAttempts);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
            }
        }
    }

    private function lockRow(): void
    {
        $locked = $this->connection->table($this->table)
            ->where($this->keyName, $this->key)
            ->lockForUpdate()
            ->value($this->keyName);

        if ($locked === null) {
            throw new LogicException("Cannot lock missing resource row {$this->table}.{$this->keyName} = {$this->key}.");
        }
    }
}
