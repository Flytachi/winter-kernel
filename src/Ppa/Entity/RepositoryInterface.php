<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Entity;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryException;
use ValueError;

/**
 * Contract for all repository query-builder implementations.
 *
 * Defines the full SQL clause surface in logical execution order:
 * `WITH` → `SELECT` → `FROM` → alias → `JOIN` → `WHERE` →
 * `GROUP BY` → `HAVING` → `UNION` → `ORDER BY` → `LIMIT` → `FOR`.
 *
 * Extended by {@see RepositoryCrudInterface} (write operations) and
 * {@see RepositoryViewInterface} (read operations). Implemented by
 * {@see \Flytachi\Winter\K2\Ppa\Repository\RepositoryCore}.
 */
interface RepositoryInterface
{
    // --- Getters ---

    /**
     * Returns an active CDO connection for this repository's config.
     *
     * @return CDO
     */
    public function db(): CDO;

    /**
     * Returns the database schema override, or null if not set.
     *
     * @return string|null
     */
    public function getSchema(): ?string;

    /**
     * Returns the fully-qualified table name, optionally prefixed with schema.
     *
     * Returns an empty string when {@see $table} is not defined.
     *
     * @return string e.g. `'public.users'` or `'users'`
     */
    public function originTable(): string;

    /**
     * Returns the entity class name used for hydrating query results.
     *
     * @return class-string
     */
    public function getEntityClassName(): string;

    /**
     * Returns the database configuration class name bound to this repository.
     *
     * @return class-string
     */
    public function getDbConfigClassName(): string;

    // --- SQL management ---

    /**
     * Assembles and returns the full SQL query string from accumulated parts.
     *
     * @param string[] $ignoreParts SQL part keys to skip during assembly
     *                              (e.g. `['order', 'limit', 'offset', 'for']`)
     * @return string Built SQL query
     * @throws RepositoryException
     */
    public function buildSql(array $ignoreParts = []): string;

    /**
     * Returns a specific SQL part by key, or the full built SQL when $param is null.
     *
     * @param string|null $param Part key (e.g. `'where'`, `'order'`, `'binds'`)
     * @return mixed SQL part value, or full SQL string
     * @throws RepositoryException
     */
    public function getSql(?string $param = null): mixed;

    /**
     * Clears one specific SQL part (by key) or all accumulated parts.
     *
     * @param string|null $param Part key to clear, or null to reset everything
     * @return void
     */
    public function cleanCache(?string $param = null): void;

    // --- Query building (SQL clause order) ---

    /**
     * Adds a Common Table Expression (CTE) to the query.
     *
     * Multiple calls append additional CTEs separated by commas.
     * Use $modifier for `'MATERIALIZED'` or `'NOT MATERIALIZED'` hints.
     *
     * Example:
     * ```
     * $repo->with('active_users', $subRepo)->buildSql();
     * // WITH active_users AS (SELECT * FROM users) SELECT * FROM users
     * ```
     *
     * @param string              $name       CTE name
     * @param RepositoryInterface $repository Subquery repository
     * @param string|null         $modifier   Optional CTE modifier
     * @return static
     */
    public function with(string $name, RepositoryInterface $repository, ?string $modifier = null): static;

    /**
     * Adds a recursive CTE (`WITH RECURSIVE`) to the query.
     *
     * @param string              $name       CTE name
     * @param RepositoryInterface $repository Recursive subquery repository
     * @return static
     */
    public function withRecursive(string $name, RepositoryInterface $repository): static;

    /**
     * Overrides the default `SELECT *` with a custom expression.
     *
     * When set, result hydration uses {@see \stdClass} regardless of the
     * configured entity class.
     *
     * @param string $option Raw SQL select expression (e.g. `'id, name'`, `'COUNT(*)'`)
     * @return static
     */
    public function select(string $option): static;

    /**
     * Overrides the default `FROM` source for the query.
     *
     * Pass a plain table name string, or a {@see RepositoryInterface} instance
     * to use a subquery. When passing a subquery, {@see as()} must be called first
     * to provide the required alias.
     *
     * @param string|RepositoryInterface $repository Table name or subquery repository
     * @return static
     * @throws RepositoryException When FROM is already set, or subquery has no alias
     */
    public function from(string|RepositoryInterface $repository): static;

    /**
     * Sets a table alias used in the `FROM` clause and column prefix for `SELECT`.
     *
     * Must be called before {@see from()} when using a subquery source.
     *
     * @param string $alias Table alias (e.g. `'u'`)
     * @return static
     */
    public function as(string $alias): static;

    /**
     * Appends an `JOIN` clause.
     *
     * @param string|RepositoryInterface $repository Table name/subquery to join
     * @param string|Qb                  $on         JOIN condition
     * @return static
     */
    public function join(string|RepositoryInterface $repository, string|Qb $on): static;

    /**
     * Appends a `CROSS JOIN` clause (no ON condition).
     *
     * @param string|RepositoryInterface $repository Table name or subquery to cross-join
     * @return static
     */
    public function joinCross(string|RepositoryInterface $repository): static;

    /**
     * Appends an `INNER JOIN` clause.
     *
     * @param string|RepositoryInterface $repository Table name/subquery to join
     * @param string|Qb                  $on         JOIN condition
     * @return static
     */
    public function joinInner(string|RepositoryInterface $repository, string|Qb $on): static;

    /**
     * Appends a `LEFT JOIN` clause.
     *
     * @param string|RepositoryInterface $repository Table name/subquery to join
     * @param string|Qb                  $on         JOIN condition
     * @return static
     */
    public function joinLeft(string|RepositoryInterface $repository, string|Qb $on): static;

    /**
     * Appends a `RIGHT JOIN` clause.
     *
     * @param string|RepositoryInterface $repository Table name/subquery to join
     * @param string|Qb                  $on         JOIN condition
     * @return static
     */
    public function joinRight(string|RepositoryInterface $repository, string|Qb $on): static;

    /**
     * Sets the `WHERE` clause from a {@see Qb} condition builder.
     *
     * Replaces any previously set WHERE clause. Passing null is a no-op.
     *
     * @param Qb|null $qb Condition builder, or null to skip
     * @return static
     */
    public function where(?Qb $qb): static;

    /**
     * Appends an `AND` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     */
    public function andWhere(Qb $qb): static;

    /**
     * Appends an `OR` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     */
    public function orWhere(Qb $qb): static;

    /**
     * Appends an ` XOR ` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     */
    public function xorWhere(Qb $qb): static;

    /**
     * Sets the `GROUP BY` clause.
     *
     * @param string $context Column list (e.g. `'status, type'`)
     * @return static
     */
    public function groupBy(string $context): static;

    /**
     * Sets the `HAVING` clause.
     *
     * @param string $context Having expression (e.g. `'COUNT(*) > 1'`)
     * @return static
     */
    public function having(string $context): static;

    /**
     * Appends a `UNION` with another repository query.
     *
     * @param RepositoryInterface $repository Repository whose SQL is unioned
     * @return static
     */
    public function union(RepositoryInterface $repository): static;

    /**
     * Appends a `UNION ALL` with another repository query.
     *
     * @param RepositoryInterface $repository Repository whose SQL is unioned (with duplicates)
     * @return static
     */
    public function unionAll(RepositoryInterface $repository): static;

    /**
     * Sets the `ORDER BY` clause.
     *
     * @param string $context Order expression (e.g. `'id DESC'`, `'name ASC, created_at DESC'`)
     * @return static
     */
    public function orderBy(string $context): static;

    /**
     * Sets `LIMIT` and optionally `OFFSET` for the query.
     *
     * @param int $limit  Number of rows to return (must be ≥ 1)
     * @param int $offset Number of rows to skip (must be ≥ 0, default 0)
     * @return static
     * @throws ValueError When limit < 1 or offset < 0
     */
    public function limit(int $limit, int $offset = 0): static;

    /**
     * Sets the `FOR` locking clause (e.g. `FOR UPDATE`, `FOR SHARE`).
     *
     * @param string $context Locking mode (e.g. `'UPDATE'`, `'SHARE'`)
     * @return static
     */
    public function forBy(string $context): static;

    // --- Binds management ---

    /**
     * Merges an array of bind parameters into the accumulated binds for this query.
     *
     * Passing null or an empty array is a safe no-op.
     *
     * @param CDOBind[]|null $binds Array of bind objects to merge, or null
     * @return static
     */
    public function binding(?array $binds): static;
}
