<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Entity;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Kernel\Ppa\Repository\RepositoryException;

/**
 * Contract for repository classes that support write operations.
 *
 * Extends {@see RepositoryInterface} with INSERT, UPDATE, DELETE, and UPSERT
 * capabilities. Implemented by {@see \Flytachi\Winter\Kernel\Ppa\Repository\RepositoryCrudTrait}
 * and exposed via {@see \Flytachi\Winter\Kernel\Ppa\Stereotype\RepositoryCrud} and
 * {@see \Flytachi\Winter\Kernel\Ppa\Stereotype\Repository}.
 */
interface RepositoryCrudInterface extends RepositoryInterface
{
    /**
     * Inserts a single entity or associative array into the table.
     *
     * @param object|array $entity Entity object or associative column-value array
     * @return mixed Last insert ID or driver-specific return value
     * @throws RepositoryException
     */
    public function insert(object|array $entity): mixed;

    /**
     * Inserts many entities, sent to the database in batches.
     *
     * One rule, applied per argument: **an array is one row, anything traversable is
     * a stream of rows.** So entities, unpacked arrays and generators all work, in
     * any combination — and a generator keeps peak memory at one batch whatever the
     * total.
     *
     * @param iterable|object ...$entities Entities, streams of entities, or both
     * @return void
     * @throws RepositoryException
     */
    public function insertBatch(iterable|object ...$entities): void;

    /**
     * Updates rows matching the given condition.
     *
     * @param object|array $entity  Column-value map of fields to update
     * @param Qb           $qb      WHERE condition (required — prevents accidental full-table updates)
     * @return int|string Number of affected rows or driver-specific return value
     * @throws RepositoryException
     */
    public function update(object|array $entity, Qb $qb): int|string;

    /**
     * Deletes rows matching the given condition.
     *
     * @param Qb $qb WHERE condition (required — prevents accidental full-table deletes)
     * @return int|string Number of affected rows or driver-specific return value
     * @throws RepositoryException
     */
    public function delete(Qb $qb): int|string;

    /**
     * Inserts an entity, updating specified columns on conflict.
     *
     * @param object|array  $entity          Entity to insert or update
     * @param array         $conflictColumns Columns that define the conflict target
     * @param array|null    $updateColumns   Columns to update on conflict; null updates all non-conflict columns
     * @return mixed Last insert ID or driver-specific return value
     * @throws RepositoryException
     */
    public function upsert(object|array $entity, array $conflictColumns, ?array $updateColumns = null): mixed;

    /**
     * Upserts many entities, sent to the database in batches.
     *
     * @param iterable $entities Entities to upsert — array, generator, any Traversable
     * @param array $conflictColumns Columns that define the conflict target
     * @param array|null $updateColumns Column => expression map; null or [] ignores conflicts
     * @return void
     * @throws RepositoryException
     */
    public function upsertBatch(iterable $entities, array $conflictColumns, ?array $updateColumns = null): void;
}
