<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Entity;

use Flytachi\Winter\Cdo\Qb;

/**
 * Contract for repository classes that support write operations.
 *
 * Extends {@see RepositoryInterface} with INSERT, UPDATE, DELETE, and UPSERT
 * capabilities. Implemented by {@see \Flytachi\Winter\K2\Ppa\Repository\RepositoryCrudTrait}
 * and exposed via {@see \Flytachi\Winter\K2\Ppa\Stereotype\RepositoryCrud} and
 * {@see \Flytachi\Winter\K2\Ppa\Stereotype\Repository}.
 */
interface RepositoryCrudInterface extends RepositoryInterface
{
    /**
     * Inserts a single entity or associative array into the table.
     *
     * @param object|array $entity Entity object or associative column-value array
     * @return mixed Last insert ID or driver-specific return value
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function insert(object|array $entity): mixed;

    /**
     * Inserts multiple entities in a single batch statement.
     *
     * @param array|object ...$entities One or more entities to insert
     * @return void
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function insertGroup(array|object ...$entities): void;

    /**
     * Updates rows matching the given condition.
     *
     * @param object|array $entity  Column-value map of fields to update
     * @param Qb           $qb      WHERE condition (required — prevents accidental full-table updates)
     * @return int|string Number of affected rows or driver-specific return value
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function update(object|array $entity, Qb $qb): int|string;

    /**
     * Deletes rows matching the given condition.
     *
     * @param Qb $qb WHERE condition (required — prevents accidental full-table deletes)
     * @return int|string Number of affected rows or driver-specific return value
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function delete(Qb $qb): int|string;

    /**
     * Inserts an entity, updating specified columns on conflict.
     *
     * @param object|array  $entity          Entity to insert or update
     * @param array         $conflictColumns Columns that define the conflict target
     * @param array|null    $updateColumns   Columns to update on conflict; null updates all non-conflict columns
     * @return mixed Last insert ID or driver-specific return value
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function upsert(object|array $entity, array $conflictColumns, ?array $updateColumns = null): mixed;

    /**
     * Batch-inserts multiple entities, updating specified columns on conflict.
     *
     * @param array      $entities        Array of entities to upsert
     * @param array      $conflictColumns Columns that define the conflict target
     * @param array|null $updateColumns   Columns to update on conflict; null updates all non-conflict columns
     * @return void
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function upsertGroup(array $entities, array $conflictColumns, ?array $updateColumns = null): void;
}
