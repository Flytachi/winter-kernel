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
     * Inserts multiple entities in a single batch statement.
     *
     * @see CDO::insertGroup()
     * @param array|object ...$entities One or more entities to insert
     * @return void
     * @throws RepositoryException
     */
    public function insertGroup(array|object ...$entities): void
    {
        try {
            $this->db()->insertGroup($this->originTable(), $entities);
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
     * Batch-inserts multiple entities, updating specified columns on conflict.
     *
     * @see CDO::upsertGroup()
     * @param array      $entities        Array of entities to upsert
     * @param array      $conflictColumns Columns that define the conflict target
     * @param array|null $updateColumns   Columns to update on conflict; null updates all non-conflict columns
     * @return void
     * @throws RepositoryException
     */
    public function upsertGroup(
        array $entities,
        array $conflictColumns,
        ?array $updateColumns = null
    ): void {
        try {
            $this->db()->upsertGroup($this->originTable(), $entities, $conflictColumns, $updateColumns);
        } catch (CDOException $exception) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $exception);
            throw new RepositoryException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
