<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Entity;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\Qb;

/**
 * Contract for repository classes that support read operations.
 *
 * Extends {@see RepositoryInterface} with a full suite of SELECT helpers:
 * raw SQL execution, single/collection fetch, count, exists, and
 * static convenience finders with optional throw-on-miss variants.
 *
 * Implemented by {@see \Flytachi\Winter\K2\Ppa\Repository\RepositoryViewTrait}
 * and exposed via {@see \Flytachi\Winter\K2\Ppa\Stereotype\RepositoryView} and
 * {@see \Flytachi\Winter\K2\Ppa\Stereotype\Repository}.
 */
interface RepositoryViewInterface extends RepositoryInterface
{
    /**
     * Executes a raw SQL query with explicit binds and returns hydrated objects.
     *
     * @param string      $sql             Raw SQL string with named placeholders
     * @param array       $binds           Array of {@see \Flytachi\Winter\Cdo\CDOBind} objects
     * @param string|null $entityClassName Override entity class for hydration; null uses repository default
     * @return array<object> Array of hydrated objects
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function rawFetch(string $sql, array $binds = [], ?string $entityClassName = null): array;

    /**
     * Executes the built query and returns the first matching row, or null.
     *
     * Automatically applies `LIMIT 1`.
     *
     * @param string|null $entityClassName Override entity class for hydration
     * @return object|null First matching entity, or null
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function find(?string $entityClassName = null): ?object;

    /**
     * Executes the built query and returns a single column value from the first row.
     *
     * Automatically applies `LIMIT 1`.
     *
     * @param int $column Zero-based column index (default 0)
     * @return mixed Column value, or false if no row found
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function findColumn(int $column = 0): mixed;

    /**
     * Executes the built query and returns all matching rows.
     *
     * @param string|null $entityClassName Override entity class for hydration
     * @return array<object> Array of hydrated objects
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function findAll(?string $entityClassName = null): array;

    /**
     * Returns the row count for the built query using `COUNT(*)`.
     *
     * If a custom {@see select()} is already set, wraps it: `COUNT(custom_expr)`.
     *
     * @return int Row count
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function count(): int;

    /**
     * Returns true if at least one row matches the built query.
     *
     * Uses `SELECT 1 LIMIT 1` internally for efficiency.
     *
     * @return bool
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public function exists(): bool;

    /**
     * Finds a single record by its primary key.
     *
     * @param int|string  $id              Primary key value
     * @param string|null $entityClassName Override entity class for hydration
     * @return object|null Matching entity, or null
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public static function findById(int|string $id, ?string $entityClassName = null): ?object;

    /**
     * Finds a single record matching the given condition.
     *
     * @param Qb          $qb              WHERE condition
     * @param string|null $entityClassName Override entity class for hydration
     * @return object|null Matching entity, or null
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public static function findBy(Qb $qb, ?string $entityClassName = null): ?object;

    /**
     * Finds all records matching the given condition, or all rows when $qb is null.
     *
     * @param Qb|null     $qb              WHERE condition, or null to fetch all
     * @param string|null $entityClassName Override entity class for hydration
     * @return array<object>
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public static function findAllBy(?Qb $qb = null, ?string $entityClassName = null): array;

    /**
     * Finds a record by its primary key, or throws if not found.
     *
     * @param int|string  $id              Primary key value
     * @param string|null $entityClassName Override entity class for hydration
     * @param string      $message         Exception message on not-found
     * @param HttpCode    $httpCode        HTTP status code on not-found
     * @return object Matching entity (never null)
     * @throws EntityException  When the record is not found
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public static function findByIdOrThrow(
        int|string $id,
        ?string $entityClassName = null,
        string $message = 'Entity not found',
        HttpCode $httpCode = HttpCode::NOT_FOUND
    ): object;

    /**
     * Finds a record matching the given condition, or throws if not found.
     *
     * @param Qb          $qb              WHERE condition
     * @param string|null $entityClassName Override entity class for hydration
     * @param string      $message         Exception message on not-found
     * @param HttpCode    $httpCode        HTTP status code on not-found
     * @return object Matching entity (never null)
     * @throws EntityException  When no record matches the condition
     * @throws \Flytachi\Winter\K2\Ppa\Repository\RepositoryException
     */
    public static function findByOrThrow(
        Qb $qb,
        ?string $entityClassName = null,
        string $message = 'Entity not found',
        HttpCode $httpCode = HttpCode::NOT_FOUND
    ): object;
}
