<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Entity;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Kernel\Ppa\Repository\RepositoryException;
use Flytachi\Winter\Kernel\Ppa\Stereotype\Repository;

/**
 * Contract for repository classes that support read operations.
 *
 * Extends {@see RepositoryInterface} with a full suite of SELECT helpers:
 * raw SQL execution, single/collection fetch, count, exists, and
 * static convenience finders with optional throw-on-miss variants.
 *
 * Implemented by {@see \Flytachi\Winter\Kernel\Ppa\Repository\RepositoryViewTrait}
 * and exposed via {@see \Flytachi\Winter\Kernel\Ppa\Stereotype\RepositoryView} and
 * {@see Repository}.
 *
 * `TEntity` is the entity class declared by a concrete repository via
 * `protected string $entityClassName`. Subclasses bind it through an `@extends`
 * PHPDoc tag pinning the template parameter — see {@see Repository} for details.
 *
 * Finder methods (`find`, `findAll`, `findById`, ...) accept an optional
 * `$entityClassName` override. The return type is inferred via a conditional:
 * when omitted, the return falls back to `TEntity`; when provided, it is the
 * `TOverride` class declared via a method-level `@template`.
 *
 * @template TEntity of object
 */
interface RepositoryViewInterface extends RepositoryInterface
{
    /**
     * Executes a raw SQL query with explicit binds and returns hydrated objects.
     *
     * @template TOverride of object
     * @param string $sql Raw SQL string with named placeholders.
     * @param array $binds Array of {@see \Flytachi\Winter\Cdo\CDOBind} objects.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration;
     *                                                      `null` uses the repository default.
     * @return ($entityClassName is null ? list<TEntity> : list<TOverride>) Array of hydrated objects.
     * @throws RepositoryException
     */
    public function rawFetch(string $sql, array $binds = [], ?string $entityClassName = null): array;

    /**
     * Executes the built query and returns the first matching row, or null.
     *
     * Automatically applies `LIMIT 1`.
     *
     * @template TOverride of object
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? TEntity|null : TOverride|null) First matching entity, or `null`.
     * @throws RepositoryException
     */
    public function find(?string $entityClassName = null): ?object;

    /**
     * Executes the built query and returns a single column value from the first row.
     *
     * Automatically applies `LIMIT 1`.
     *
     * @param int $column Zero-based column index (default 0)
     * @return mixed Column value, or false if no row found
     * @throws RepositoryException
     */
    public function findColumn(int $column = 0): mixed;

    /**
     * Executes the built query and returns all matching rows.
     *
     * @template TOverride of object
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? list<TEntity> : list<TOverride>) Array of hydrated objects.
     * @throws RepositoryException
     */
    public function findAll(?string $entityClassName = null): array;

    /**
     * Returns the row count for the built query using `COUNT(*)`.
     *
     * If a custom {@see select()} is already set, wraps it: `COUNT(custom_expr)`.
     *
     * @return int Row count
     * @throws RepositoryException
     */
    public function count(): int;

    /**
     * Returns true if at least one row matches the built query.
     *
     * Uses `SELECT 1 LIMIT 1` internally for efficiency.
     *
     * @return bool
     * @throws RepositoryException
     */
    public function exists(): bool;

    /**
     * Finds a single record by its primary key.
     *
     * @template TOverride of object
     * @param int|string $id Primary key value.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? TEntity|null : TOverride|null) Matching entity, or `null`.
     * @throws RepositoryException
     */
    public static function findById(int|string $id, ?string $entityClassName = null): ?object;

    /**
     * Finds a single record matching the given condition.
     *
     * @template TOverride of object
     * @param Qb $qb WHERE condition.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? TEntity|null : TOverride|null) Matching entity, or `null`.
     * @throws RepositoryException
     */
    public static function findBy(Qb $qb, ?string $entityClassName = null): ?object;

    /**
     * Finds all records matching the given condition, or all rows when `$qb` is `null`.
     *
     * @template TOverride of object
     * @param Qb|null $qb WHERE condition, or `null` to fetch all.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? list<TEntity> : list<TOverride>) Matching entities.
     * @throws RepositoryException
     */
    public static function findAllBy(?Qb $qb = null, ?string $entityClassName = null): array;

    /**
     * Finds a record by its primary key, or throws if not found.
     *
     * @template TOverride of object
     * @param int|string $id Primary key value.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @param string $message Exception message on not-found.
     * @param HttpCode $httpCode HTTP status code on not-found.
     * @return ($entityClassName is null ? TEntity : TOverride) Matching entity (never `null`).
     * @throws EntityException When the record is not found.
     * @throws RepositoryException
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
     * @template TOverride of object
     * @param Qb $qb WHERE condition.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @param string $message Exception message on not-found.
     * @param HttpCode $httpCode HTTP status code on not-found.
     * @return ($entityClassName is null ? TEntity : TOverride) Matching entity (never `null`).
     * @throws EntityException When no record matches the condition.
     * @throws RepositoryException
     */
    public static function findByOrThrow(
        Qb $qb,
        ?string $entityClassName = null,
        string $message = 'Entity not found',
        HttpCode $httpCode = HttpCode::NOT_FOUND
    ): object;
}
