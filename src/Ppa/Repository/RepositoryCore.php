<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Repository;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Ppa\Entity\EntityInterface;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\K2\Ppa\Mapping\RepositoryMappingInterface;
use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Base\Runtime;
use PDOStatement;
use stdClass;
use Swoole\Coroutine;
use Throwable;
use ValueError;

/**
 * Abstract base class for all repository implementations.
 *
 * Provides a fluent SQL query builder that follows SQL clause order:
 * `WITH [RECURSIVE]` → `SELECT` → `FROM` → alias → `JOIN` → `WHERE` →
 * `GROUP BY` → `HAVING` → `UNION` → `ORDER BY` → `LIMIT / OFFSET` → `FOR`.
 *
 * Subclasses must define {@see $dbConfigClassName} and {@see $table}.
 * Optionally override {@see $entityClassName} for typed result hydration,
 * and {@see $schema} to pin a specific database schema.
 *
 * Typical usage via stereotype:
 * ```
 * class UserRepository extends RepositoryCrud
 * {
 *     protected string $dbConfigClassName = DbConfig::class;
 *     protected string $entityClassName   = UserEntity::class;
 *     public static string $table         = 'users';
 * }
 *
 * $users = UserRepository::instance('u')
 *     ->joinLeft('orders o', 'u.id = o.user_id')
 *     ->where(Qb::eq('u.status', 'active'))
 *     ->orderBy('u.id DESC')
 *     ->limit(20)
 *     ->findAll();
 * ```
 *
 * `TEntity` is the entity class declared by a concrete repository via
 * {@see $entityClassName}. Subclasses bind it through an `@extends` PHPDoc tag
 * pinning the template parameter — see {@see \Flytachi\Winter\K2\Ppa\Stereotype\Repository}
 * for details. When unbound, `TEntity` defaults to {@see stdClass}.
 *
 * @template TEntity of object
 * @see RepositoryCrudTrait  for INSERT / UPDATE / DELETE / UPSERT operations
 * @see RepositoryViewTrait  for SELECT / find / count / exists operations
 */
abstract class RepositoryCore implements RepositoryInterface, RepositoryMappingInterface
{
    /** @var class-string $dbConfigClassName dbConfig class name (default => DbConfig::class) */
    protected string $dbConfigClassName;
    /** @var class-string<TEntity> $entityClassName object class name (default => \stdClass::class) */
    protected string $entityClassName = stdClass::class;
    /** @var string|null $schema schema in database */
    protected ?string $schema = null;
    /** @var string $table name of the table in the database */
    public static string $table = '';
    /** @var array $sqlParts sql parameters (FPM backing store; Swoole uses per-coroutine state) */
    protected array $sqlParts = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function __construct()
    {
        if (!isset($this->dbConfigClassName)) {
            RepositoryException::throw(static::class . ' $dbConfigClassName must be set by the child class');
        }
        $config = PpaConnectionPool::getConfigDb($this->dbConfigClassName);
        if ($this->schema == null) {
            $this->schema = $config->getSchema();
        }
    }

    /**
     * Creates and returns a new repository instance, optionally with a table alias.
     *
     * @param string|null $as Optional table alias — calls {@see as()} before returning
     * @return static
     */
    public static function instance(?string $as = null): static
    {
        $repository = new static();
        if (!empty($as)) {
            $repository->as($as);
        }
        return $repository;
    }

    // -------------------------------------------------------------------------
    // Coroutine-safe state
    // -------------------------------------------------------------------------

    /**
     * Returns the per-coroutine mutable state object.
     *
     * **FPM** (no active coroutine): returns `$this` directly, so that
     * `$this->state()->sqlParts` is identical to `$this->sqlParts` — zero
     * overhead and identical semantics to the original code.
     *
     * **Swoole coroutine**: returns a `stdClass` stored in the current
     * coroutine's context keyed by this object's identity.  Each coroutine gets
     * its own isolated copy of `sqlParts` and `entityClassName`, preventing
     * cross-coroutine state corruption when the DI container reuses the same
     * Repository singleton across concurrent requests.
     */
    protected function state(): object
    {
        if (!Runtime::isSwooleCoroutine()) {
            return $this;
        }
        $ctx = Coroutine::getContext();
        $key = '__rp_' . spl_object_id($this);
        if (!isset($ctx[$key])) {
            $state                  = new stdClass();
            $state->sqlParts        = [];
            $state->entityClassName = $this->entityClassName;
            $ctx[$key]              = $state;
        }
        return $ctx[$key];
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    /**
     * Returns the database configuration class name bound to this repository.
     *
     * @return class-string
     */
    final public function getDbConfigClassName(): string
    {
        return $this->dbConfigClassName;
    }

    /**
     * Returns the entity class name used for hydrating query results.
     *
     * When a custom {@see select()} is active, the effective hydration target
     * is {@see stdClass} regardless of the configured entity — a custom SELECT
     * returns arbitrary columns that may not match the entity's shape.
     *
     * Read-only: the configured `$entityClassName` is never mutated.
     *
     * @return class-string<TEntity>|class-string<stdClass>
     */
    final public function getEntityClassName(): string
    {
        $state = $this->state();
        return isset($state->sqlParts['option']) ? stdClass::class : $state->entityClassName;
    }

    /**
     * @return CDO
     */
    public function db(): CDO
    {
        return PpaConnectionPool::db($this->dbConfigClassName);
    }

    /**
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }

    /**
     * @return string
     */
    public function originTable(): string
    {
        if (empty(static::$table)) {
            return '';
        }
        return (($this->schema) ? $this->schema . '.' : '') . static::$table;
    }

    // -------------------------------------------------------------------------
    // SQL management
    // -------------------------------------------------------------------------

    /**
     * Assembles the full SQL query string from accumulated parts.
     *
     * @param string[] $ignoreParts SQL part keys to skip during assembly
     *                              (e.g. `['order', 'limit', 'offset', 'for']`)
     * @throws RepositoryException
     */
    public function buildSql(array $ignoreParts = []): string
    {
        try {
            $state = $this->state();
            $skip = array_flip($ignoreParts);
            $parts = ['SELECT ' . $this->prepareSelect()];
            if (!empty(($state->sqlParts['from'] ?? $this->originTable()))) {
                $parts[] = 'FROM ' . ($state->sqlParts['from'] ?? $this->originTable());
            }

            foreach (['as', 'join', 'where', 'group', 'having', 'union', 'order'] as $key) {
                if (isset($state->sqlParts[$key]) && !isset($skip[$key])) {
                    $parts[] = trim($state->sqlParts[$key]);
                }
            }
            if (isset($state->sqlParts['limit']) && !isset($skip['limit'])) {
                $parts[] = 'LIMIT ' . $state->sqlParts['limit'];
            }
            if (isset($state->sqlParts['offset']) && !isset($skip['offset'])) {
                $parts[] = 'OFFSET ' . $state->sqlParts['offset'];
            }
            if (isset($state->sqlParts['for']) && !isset($skip['for'])) {
                $parts[] = 'FOR ' . $state->sqlParts['for'];
            }

            if (isset($state->sqlParts['with'])) {
                $withKeyword = isset($state->sqlParts['with_recursive']) ? 'WITH RECURSIVE' : 'WITH';
                array_unshift($parts, $withKeyword . ' ' . $state->sqlParts['with']);
            }

            return implode(' ', $parts);
        } catch (Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Returns a specific SQL part by key, or the full built SQL when $param is null.
     *
     * @param string|null $param Part key (e.g. `'where'`, `'order'`, `'binds'`), or null for full SQL
     * @return mixed SQL part value, or full SQL string
     * @throws RepositoryException
     */
    final public function getSql(?string $param = null): mixed
    {
        if ($param) {
            $state = $this->state();
            return $state->sqlParts[$param] ?? null;
        } else {
            return $this->buildSql();
        }
    }

    /**
     * Returns the number of accumulated SQL parts.
     *
     * Used internally when composing JOIN subqueries to decide whether
     * a sibling repository needs to be rendered as a subquery.
     */
    final public function sqlPartsCount(): int
    {
        return count($this->state()->sqlParts);
    }

    /**
     * Clears one specific SQL part (by key) or all accumulated SQL parts.
     *
     * In Swoole coroutine mode a full reset (`$param === null`) discards the
     * entire per-coroutine state object as the next {@see state()} call
     * re-initialises it from the class-defined defaults — including
     * `entityClassName`.
     *
     * @param string|null $param Part key to remove (e.g. `'where'`, `'order'`), or null to reset all
     * @return void
     */
    final public function cleanCache(?string $param = null): void
    {
        if (Runtime::isSwooleCoroutine()) {
            $ctx = Coroutine::getContext();
            $key = '__rp_' . spl_object_id($this);
            if ($param) {
                if (isset($ctx[$key]->sqlParts[$param])) {
                    unset($ctx[$key]->sqlParts[$param]);
                }
            } else {
                unset($ctx[$key]); // full reset: re-init from defaults on next state() call
            }
        } else {
            if ($param) {
                if (isset($this->sqlParts[$param])) {
                    unset($this->sqlParts[$param]);
                }
            } else {
                $this->sqlParts = [];
            }
        }
    }

    // -------------------------------------------------------------------------
    // Query building — WITH
    // -------------------------------------------------------------------------

    /**
     * @param string $name
     * @param RepositoryInterface $repository
     * @param string|null $modifier e.g. 'MATERIALIZED', 'NOT MATERIALIZED'
     * @return static
     */
    final public function with(string $name, RepositoryInterface $repository, ?string $modifier = null): static
    {
        $state = $this->state();
        $this->binding($repository->getSql('binds'));
        $cte = $modifier !== null
            ? $name . ' AS ' . $modifier . ' (' . $repository->buildSql() . ')'
            : $name . ' AS (' . $repository->buildSql() . ')';

        if (isset($state->sqlParts['with'])) {
            $state->sqlParts['with'] .= ', ' . $cte;
        } else {
            $state->sqlParts['with'] = $cte;
        }
        return $this;
    }

    /**
     * @param string $name
     * @param RepositoryInterface $repository
     * @return static
     */
    final public function withRecursive(string $name, RepositoryInterface $repository): static
    {
        $this->state()->sqlParts['with_recursive'] = true;
        return $this->with($name, $repository);
    }

    // -------------------------------------------------------------------------
    // Query building — SELECT
    // -------------------------------------------------------------------------

    /**
     * @param string $option
     * @return static
     */
    final public function select(string $option): static
    {
        if (!empty($option)) {
            $this->state()->sqlParts['option'] = $option;
        }
        return $this;
    }

    /**
     * Builds the SELECT-list expression for the current query.
     *
     * Resolution order:
     *  1. Custom `select()` (`sqlParts['option']`) — returned as-is.
     *  2. `$state->entityClassName` — the repository-configured entity.
     *
     * Pure read of state: no mutation. Per-call hydration overrides (e.g.
     * `find(OtherEntity::class)`) do **not** affect the SELECT list; they
     * only change the hydration target. To select a different column set,
     * call {@see select()} explicitly.
     */
    private function prepareSelect(): string
    {
        $state = $this->state();
        if (isset($state->sqlParts['option'])) {
            return $state->sqlParts['option'];
        }
        $entity = $state->entityClassName;
        if ($entity === stdClass::class || is_subclass_of($entity, stdClass::class)) {
            return '*';
        }
        $prefix = isset($state->sqlParts['as']) ? $state->sqlParts['as'] . '.' : '';
        $values = [];
        $selection = [];
        if (is_subclass_of($entity, EntityInterface::class)) {
            $selection = $entity::selection();
        }
        foreach (get_class_vars($entity) as $name => $val) {
            $values[] = $selection[$name] ?? ($prefix . $name);
        }
        return implode(', ', $values);
    }

    // -------------------------------------------------------------------------
    // Query building — FROM
    // -------------------------------------------------------------------------

    /**
     * @param string|RepositoryInterface $repository
     * @return static
     */
    final public function from(string|RepositoryInterface $repository): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['from'])) {
            RepositoryException::throw('FROM clause already set: only one FROM source is allowed');
        }
        if (is_string($repository)) {
            $state->sqlParts['from'] = $repository;
        } else {
            if (!isset($state->sqlParts['as'])) {
                RepositoryException::throw('FROM subquery requires an alias: call ->as() before ->from()');
            }
            $this->binding($repository->getSql('binds'));
            $state->sqlParts['from'] = '(' . $repository->getSql() . ')';
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — AS (alias)
    // -------------------------------------------------------------------------

    /**
     * @param string $alias
     * @return static
     */
    final public function as(string $alias): static
    {
        if (!empty($alias)) {
            $this->state()->sqlParts['as'] = $alias;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — JOIN
    // -------------------------------------------------------------------------

    private function joinedContext(string|RepositoryInterface $repository, string|Qb $on): string
    {
        if ($on instanceof Qb) {
            $this->binding($on->getBinds());
            $onSql = $on->getQuery();
        } else {
            $onSql = $on;
        }
        if (is_string($repository)) {
            return $repository . " ON(" . $onSql . ")";
        }
        if ($repository->sqlPartsCount() > 1) {
            $this->binding($repository->getSql('binds'));
            return '(' . $repository->getSql() . ') '
                . $repository->getSql('as') . " ON(" . $onSql . ")";
        } else {
            return $repository->originTable()
                . ' ' . $repository->getSql('as') . " ON(" . $onSql . ")";
        }
    }

    /**
     * @param string|RepositoryInterface $repository
     * @return static
     */
    final public function joinCross(string|RepositoryInterface $repository): static
    {
        if (!is_string($repository)) {
            if ($repository->sqlPartsCount() > 1) {
                $this->binding($repository->getSql('binds'));
                $repository = '(' . $repository->getSql() . ') ' . $repository->getSql('as');
            } else {
                $repository = $repository->originTable() . ' ' . $repository->getSql('as');
            }
        }
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' CROSS JOIN ' . $repository;
        } else {
            $state->sqlParts['join'] = 'CROSS JOIN ' . $repository;
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     */
    final public function join(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     */
    final public function joinInner(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' INNER JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'INNER JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     */
    final public function joinLeft(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' LEFT JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'LEFT JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     */
    final public function joinRight(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' RIGHT JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'RIGHT JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — WHERE
    // -------------------------------------------------------------------------

    /**
     * @param null|Qb $qb
     * @return static
     */
    final public function where(?Qb $qb): static
    {
        if (!is_null($qb)) {
            if ($qb->getQuery()) {
                $state = $this->state();
                $state->sqlParts['where'] = 'WHERE ' . $qb->getQuery();
                $this->binding($qb->getBinds());
            }
        }
        return $this;
    }

    /**
     * Appends an `AND` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     */
    final public function andWhere(Qb $qb): static
    {
        return $this->addWhere($qb, 'AND');
    }

    /**
     * Appends an `OR` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     */
    final public function orWhere(Qb $qb): static
    {
        return $this->addWhere($qb, 'OR');
    }

    /**
     * Appends an ` XOR ` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     */
    final public function xorWhere(Qb $qb): static
    {
        return $this->addWhere($qb, 'XOR');
    }

    private function addWhere(Qb $qb, string $operator): static
    {
        if ($qb->getQuery()) {
            $state = $this->state();
            if (empty($state->sqlParts['where'])) {
                $state->sqlParts['where'] = 'WHERE ' . $qb->getQuery();
            } else {
                $state->sqlParts['where'] .= " $operator " . $qb->getQuery();
            }
            $this->binding($qb->getBinds());
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — GROUP BY / HAVING
    // -------------------------------------------------------------------------

    /**
     * @param string $context
     * @return static
     */
    final public function groupBy(string $context): static
    {
        if (!empty($context)) {
            $this->state()->sqlParts['group'] = 'GROUP BY ' . $context;
        }
        return $this;
    }

    /**
     * @param string $context
     * @return static
     */
    final public function having(string $context): static
    {
        if (!empty($context)) {
            $this->state()->sqlParts['having'] = 'HAVING ' . $context;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — UNION
    // -------------------------------------------------------------------------

    /**
     * @param RepositoryInterface $repository
     * @return static
     */
    final public function union(RepositoryInterface $repository): static
    {
        return $this->addUnion($repository, 'UNION');
    }

    /**
     * @param RepositoryInterface $repository
     * @return static
     */
    final public function unionAll(RepositoryInterface $repository): static
    {
        return $this->addUnion($repository, 'UNION ALL');
    }

    private function addUnion(RepositoryInterface $repository, string $keyword): static
    {
        $state = $this->state();
        $this->binding($repository->getSql('binds'));
        $unionPart = $keyword . ' ' . $repository->buildSql();

        if (isset($state->sqlParts['union'])) {
            $state->sqlParts['union'] .= ' ' . $unionPart;
        } else {
            $state->sqlParts['union'] = $unionPart;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — ORDER BY / LIMIT / FOR
    // -------------------------------------------------------------------------

    /**
     * @param string $context
     * @return static
     */
    final public function orderBy(string $context): static
    {
        if (!empty($context)) {
            $this->state()->sqlParts['order'] = 'ORDER BY ' . $context;
        }
        return $this;
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return static
     */
    final public function limit(int $limit, int $offset = 0): static
    {
        if ($limit < 1) {
            throw new ValueError("LIMIT must be a positive integer (>= 1), got: $limit.");
        }
        if ($offset < 0) {
            throw new ValueError("OFFSET must be a non-negative integer (>= 0), got: $offset.");
        }
        $state = $this->state();
        $state->sqlParts['limit'] = $limit;
        if ($offset > 0) {
            $state->sqlParts['offset'] = $offset;
        }
        return $this;
    }

    /**
     * @param string $context
     * @return static
     */
    final public function forBy(string $context): static
    {
        $this->state()->sqlParts['for'] = $context;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Binds management
    // -------------------------------------------------------------------------

    /**
     * Merges an array of bind parameters into the accumulated binds for this query.
     *
     * Called internally by `where()`, `join*()`, `with()`, `union*()`, and `from()`
     * to collect all `CDOBind` objects before execution. Can also be called directly
     * to attach custom binds when composing raw SQL fragments.
     *
     * Passing null or an empty array is a safe no-op.
     *
     * @param CDOBind[]|null $binds Array of {@see CDOBind} objects to merge, or null
     * @return static
     */
    final public function binding(?array $binds): static
    {
        if (empty($binds)) {
            return $this;
        }
        $state = $this->state();
        foreach ($binds as $bind) {
            $state->sqlParts['binds'][$bind->getName()] = $bind;
        }
        return $this;
    }

    /**
     * Binds all accumulated parameters to a prepared statement before execution.
     *
     * Uses `bindTypedValue()` when available (CDOStatement), otherwise falls back
     * to `bindValue()` (PDOStatement). Called internally by all fetch methods in
     * {@see RepositoryViewTrait} immediately after `prepare()`.
     *
     * @param CDOStatement|PDOStatement $stmt Prepared statement to bind values onto
     * @return void
     */
    final protected function useBind(CDOStatement|PDOStatement $stmt): void
    {
        $state = $this->state();
        if (empty($state->sqlParts['binds'])) {
            return;
        }
        $method = method_exists($stmt, 'bindTypedValue') ? 'bindTypedValue' : 'bindValue';
        foreach ($state->sqlParts['binds'] as $bind) {
            $stmt->{$method}($bind->getName(), $bind->getValue());
        }
    }

    // -------------------------------------------------------------------------
    // Mapping
    // -------------------------------------------------------------------------

    public function mapIdentifierColumnName(): string
    {
        return 'id';
    }
}
