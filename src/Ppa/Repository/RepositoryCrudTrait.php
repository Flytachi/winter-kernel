<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Repository;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Kernel\Ppa\Entity\RepositoryCrudInterface;
use Flytachi\Winter\Kernel\Ppa\Pool\PpaConnectionPool;

/**
 * Provides concrete write-operation implementations for repository classes.
 *
 * Implements {@see RepositoryCrudInterface} by delegating directly to
 * {@see CDO} methods, mapping CDO exceptions to {@see RepositoryException}.
 *
 * Mix into any {@see RepositoryCore} subclass that needs write access:
 * ```
 * class UserRepository extends RepositoryCore implements RepositoryCrudInterface
 * {
 *     use RepositoryCrudTrait;
 * }
 * ```
 *
 * @mixin RepositoryCrudInterface
 */
trait RepositoryCrudTrait
{
    /**
     * Inserts a single entity or associative array into the table.
     *
     * @see CDO::insert()
     * @param object|array $entity Entity object or associative column-value array
     * @return mixed Last insert ID or driver-specific return value
     * @throws RepositoryException
     */
    public function insert(object|array $entity): mixed
    {
        try {
            return $this->db()->insert($this->originTable(), $entity);
        } catch (CDOException $exception) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $exception);
            throw new RepositoryException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Inserts many entities, sent to the database in batches.
     *
     * Takes entities directly, or a stream of them, or both:
     *
     * ```
     * $repo->insertBatch($user1, $user2);          // entities
     * $repo->insertBatch(['name' => 'John']);      // an array is one row
     * $repo->insertBatch(...$entities);            // an unpacked array
     * $repo->insertBatch($generator);              // a stream — nothing is held
     * $repo->insertBatch($fromCsv, $extraRow);     // mixed, in one call
     * ```
     *
     * The rule is per argument: **an array is one row, anything traversable is a
     * stream of rows.** There is no ambiguity — an array is a valid entity here and
     * a `Traversable` never is.
     *
     * Streaming is the reason this shape exists. Rows reach {@see CDO::insertBatch()}
     * lazily, and it flushes each batch as it fills, so peak memory follows the batch
     * size rather than the size of the job. Building the collection eagerly first —
     * `insertBatch(...$halfAMillionEntities)` — costs whatever that array costs;
     * handing over a generator costs a batch.
     *
     * @see CDO::insertBatch()
     * @param iterable|object ...$entities Entities, streams of entities, or both.
     * @return void
     * @throws RepositoryException
     */
    public function insertBatch(iterable|object ...$entities): void
    {
        try {
            $this->db()->insertBatch($this->originTable(), self::flatten($entities));
        } catch (CDOException $exception) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $exception);
            throw new RepositoryException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Updates rows matching the given condition.
     *
     * @see CDO::update()
     * @param object|array $entity  Column-value map of fields to update
     * @param Qb           $qb      WHERE condition
     * @return int|string Number of affected rows or driver-specific return value
     * @throws RepositoryException
     */
    public function update(object|array $entity, Qb $qb): int|string
    {
        try {
            return $this->db()->update($this->originTable(), $entity, $qb);
        } catch (CDOException $exception) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $exception);
            throw new RepositoryException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Deletes rows matching the given condition.
     *
     * @see CDO::delete()
     * @param Qb $qb WHERE condition
     * @return int|string Number of affected rows or driver-specific return value
     * @throws RepositoryException
     */
    public function delete(Qb $qb): int|string
    {
        try {
            return $this->db()->delete($this->originTable(), $qb);
        } catch (CDOException $exception) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $exception);
            throw new RepositoryException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Inserts an entity, updating specified columns on conflict.
     *
     * @see CDO::upsert()
     * @param object|array  $entity          Entity to insert or update
     * @param array         $conflictColumns Columns that define the conflict target
     * @param array|null    $updateColumns   Columns to update on conflict; null updates all non-conflict columns
     * @return mixed Last insert ID or driver-specific return value
     * @throws RepositoryException
     */
    public function upsert(
        object|array $entity,
        array $conflictColumns,
        ?array $updateColumns = null
    ): mixed {
        try {
            return $this->db()->upsert($this->originTable(), $entity, $conflictColumns, $updateColumns);
        } catch (CDOException $exception) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $exception);
            throw new RepositoryException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Upserts many entities, sent to the database in batches.
     *
     * `$entities` is any `iterable` — an array of entities, a generator, any
     * `Traversable`. A generator keeps peak memory at one batch whatever the total;
     * see {@see insertBatch()}.
     *
     * `$updateColumns` maps **column => expression** (`':new'` for the incoming
     * value, `':current'` for the stored one). A plain list of column names is
     * refused by CDO with a message showing the corrected call; pass `[]` or `null`
     * to ignore conflicts entirely.
     *
     * @see CDO::upsertBatch()
     * @param iterable $entities Entities to upsert.
     * @param array $conflictColumns Columns that define the conflict target.
     * @param array|null $updateColumns Column => expression map; null or [] ignores conflicts.
     * @return void
     * @throws RepositoryException
     */
    public function upsertBatch(
        iterable $entities,
        array $conflictColumns,
        ?array $updateColumns = null
    ): void {
        try {
            $this->db()->upsertBatch($this->originTable(), $entities, $conflictColumns, $updateColumns);
        } catch (CDOException $exception) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $exception);
            throw new RepositoryException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Turns the variadic arguments into one lazy stream of rows.
     *
     * An array is a row (a column-value map — the form {@see insert()} takes), while
     * anything traversable is a stream of rows to be drained. That asymmetry is not a
     * heuristic: an array is a legal entity in this API and a `Traversable` never is,
     * so no call is ambiguous.
     *
     * A generator, so a stream stays a stream all the way into the driver. Collecting
     * into an array here would put the whole job back in memory and undo the point.
     *
     * @param array<int, iterable|object> $entities
     * @return \Generator<int, object|array<string, mixed>>
     */
    private static function flatten(array $entities): \Generator
    {
        foreach ($entities as $entity) {
            if ($entity instanceof \Traversable) {
                yield from $entity;
                continue;
            }
            yield $entity;
        }
    }
}
