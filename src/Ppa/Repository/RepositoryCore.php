<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Repository;

use Flytachi\Winter\Base\Interface\Stereotype;
use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\Cdo\ConnectionPool;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Ppa\Entity\EntityInterface;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\K2\Ppa\Mapping\RepositoryMappingInterface;
use stdClass;

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
 * @see RepositoryCrudTrait  for INSERT / UPDATE / DELETE / UPSERT operations
 * @see RepositoryViewTrait  for SELECT / find / count / exists operations
 */
abstract class RepositoryCore extends Stereotype implements RepositoryInterface, RepositoryMappingInterface
{
    /** @var class-string $dbConfigClassName dbConfig class name (default => DbConfig::class) */
    protected string $dbConfigClassName;
    /** @var class-string $entityClassName object class name (default => \stdClass::class) */
    protected string $entityClassName = \stdClass::class;
    /** @var string|null $schema schema in database */
    protected ?string $schema = null;
    /** @var string $table name of the table in the database */
    public static string $table = '';
    /** @var array $sqlParts sql parameters */
    protected array $sqlParts = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function __construct()
    {
        parent::__construct();
        if (!isset($this->dbConfigClassName)) {
            RepositoryException::throw(static::class . ' $dbConfigClassName must be set by the child class');
        }
        $config = ConnectionPool::getConfigDb($this->dbConfigClassName);
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
     * @return class-string
     */
    final public function getEntityClassName(): string
    {
        return $this->entityClassName;
    }

    /**
     * @return CDO
     */
    public function db(): CDO
    {
        return ConnectionPool::db($this->dbConfigClassName);
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
     * @throws RepositoryException
     */
    public function buildSql(): string
    {
        try {
            $parts = ['SELECT ' . $this->prepareSelect()];
            if (!empty(($this->sqlParts['from'] ?? $this->originTable()))) {
                $parts[] = 'FROM ' . ($this->sqlParts['from'] ?? $this->originTable());
            }

            foreach (['as', 'join', 'where', 'group', 'having'] as $key) {
                if (isset($this->sqlParts[$key])) {
                    $parts[] = trim($this->sqlParts[$key]);
                }
            }
            if (isset($this->sqlParts['union'])) {
                $parts[] = $this->sqlParts['union'];
            }
            if (isset($this->sqlParts['order'])) {
                $parts[] = trim($this->sqlParts['order']);
            }
            if (isset($this->sqlParts['limit'])) {
                $parts[] = 'LIMIT ' . $this->sqlParts['limit'];
            }
            if (isset($this->sqlParts['offset'])) {
                $parts[] = 'OFFSET ' . $this->sqlParts['offset'];
            }
            if (isset($this->sqlParts['for'])) {
                $parts[] = 'FOR ' . $this->sqlParts['for'];
            }

            if (isset($this->sqlParts['with'])) {
                $withKeyword = isset($this->sqlParts['with_recursive']) ? 'WITH RECURSIVE' : 'WITH';
                array_unshift($parts, $withKeyword . ' ' . $this->sqlParts['with']);
            }

            $query = implode(' ', $parts);
            $this->logger->debug('Repository build:' . $query);
            return $query;
        } catch (\Throwable $th) {
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
            return (isset($this->sqlParts[$param])) ? $this->sqlParts[$param] : null;
        } else {
            return $this->buildSql();
        }
    }

    /**
     * Clears one specific SQL part (by key) or all accumulated SQL parts.
     *
     * @param string|null $param Part key to remove (e.g. `'where'`, `'order'`), or null to reset all
     * @return void
     */
    final public function cleanCache(?string $param = null): void
    {
        if ($param) {
            if (isset($this->sqlParts[$param])) {
                unset($this->sqlParts[$param]);
            };
        } else {
            $this->sqlParts = [];
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
        $this->binding($repository->getSql('binds'));
        $cte = $modifier !== null
            ? $name . ' AS ' . $modifier . ' (' . $repository->buildSql() . ')'
            : $name . ' AS (' . $repository->buildSql() . ')';

        if (isset($this->sqlParts['with'])) {
            $this->sqlParts['with'] .= ', ' . $cte;
        } else {
            $this->sqlParts['with'] = $cte;
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
        $this->sqlParts['with_recursive'] = true;
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
            $this->sqlParts['option'] = $option;
        }
        return $this;
    }

    private function prepareSelect(): string
    {
        if (isset($this->sqlParts['option'])) {
            $this->entityClassName = stdClass::class;
            return $this->sqlParts['option'];
        } elseif ($this->entityClassName === 'stdClass' || is_subclass_of($this->entityClassName, stdClass::class)) {
            return '*';
        } else {
            $prefix = isset($this->sqlParts['as']) ? $this->sqlParts['as'] . '.' : '';
            $values = [];
            $selection = [];
            if (is_subclass_of($this->entityClassName, EntityInterface::class)) {
                $selection = $this->entityClassName::selection();
            }

            foreach (get_class_vars($this->entityClassName) as $name => $val) {
                $values[] = $selection[$name] ?? ($prefix . $name);
            }

            return implode(', ', $values);
        }
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
        if (isset($this->sqlParts['from'])) {
            RepositoryException::throw('FROM clause already set: only one FROM source is allowed');
        }
        if (is_string($repository)) {
            $this->sqlParts['from'] = $repository;
        } else {
            if (!isset($this->sqlParts['as'])) {
                RepositoryException::throw('FROM subquery requires an alias: call ->as() before ->from()');
            }
            $this->binding($repository->getSql('binds'));
            $this->sqlParts['from'] = '(' . $repository->getSql() . ')';
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
            $this->sqlParts['as'] = $alias;
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
        if (count($repository->sqlParts) > 1) {
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
            if (count($repository->sqlParts) > 1) {
                $this->binding($repository->getSql('binds'));
                $repository = '(' . $repository->getSql() . ') ' . $repository->getSql('as');
            } else {
                $repository = $repository->originTable() . ' ' . $repository->getSql('as');
            }
        }
        if (isset($this->sqlParts['join'])) {
            $this->sqlParts['join'] .= ' CROSS JOIN ' . $repository;
        } else {
            $this->sqlParts['join'] = 'CROSS JOIN ' . $repository;
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string $on
     * @return static
     */
    final public function join(string|RepositoryInterface $repository, string|Qb $on): static
    {
        if (isset($this->sqlParts['join'])) {
            $this->sqlParts['join'] .= ' JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $this->sqlParts['join'] = 'JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string $on
     * @return static
     */
    final public function joinInner(string|RepositoryInterface $repository, string|Qb $on): static
    {
        if (isset($this->sqlParts['join'])) {
            $this->sqlParts['join'] .= ' INNER JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $this->sqlParts['join'] = 'INNER JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string $on
     * @return static
     */
    final public function joinLeft(string|RepositoryInterface $repository, string|Qb $on): static
    {
        if (isset($this->sqlParts['join'])) {
            $this->sqlParts['join'] .= ' LEFT JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $this->sqlParts['join'] = 'LEFT JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string $on
     * @return static
     */
    final public function joinRight(string|RepositoryInterface $repository, string|Qb $on): static
    {
        if (isset($this->sqlParts['join'])) {
            $this->sqlParts['join'] .= ' RIGHT JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $this->sqlParts['join'] = 'RIGHT JOIN ' . $this->joinedContext($repository, $on);
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
                $this->sqlParts['where'] = 'WHERE ' . $qb->getQuery();
                $this->binding($qb->getBinds());
            }
        }
        return $this;
    }

    /**
     * Appends an `AND` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, acts as {@see where()}.
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
     * If no WHERE clause exists yet, acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     */
    final public function orWhere(Qb $qb): static
    {
        return $this->addWhere($qb, 'OR');
    }

    /**
     * Appends a `XOR` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, acts as {@see where()}.
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
            if (empty($this->sqlParts['where'])) {
                $this->sqlParts['where'] = 'WHERE ' . $qb->getQuery();
            } else {
                $this->sqlParts['where'] .= " $operator " . $qb->getQuery();
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
            $this->sqlParts['group'] = 'GROUP BY ' . $context;
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
            $this->sqlParts['having'] = 'HAVING ' . $context;
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
        $this->binding($repository->getSql('binds'));
        $unionPart = $keyword . ' ' . $repository->buildSql();

        if (isset($this->sqlParts['union'])) {
            $this->sqlParts['union'] .= ' ' . $unionPart;
        } else {
            $this->sqlParts['union'] = $unionPart;
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
            $this->sqlParts['order'] = 'ORDER BY ' . $context;
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
            throw new \TypeError('limit < 1');
        }
        if ($offset < 0) {
            throw new \TypeError('offset < 0');
        }
        $this->sqlParts['limit'] = $limit;
        if ($offset > 0) {
            $this->sqlParts['offset'] = $offset;
        }
        return $this;
    }

    /**
     * @param string $context
     * @return static
     */
    final public function forBy(string $context): static
    {
        $this->sqlParts['for'] = $context;
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
        foreach ($binds as $bind) {
            $this->sqlParts['binds'][$bind->getName()] = $bind;
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
     * @param CDOStatement|\PDOStatement $stmt Prepared statement to bind values onto
     * @return void
     */
    final protected function useBind(CDOStatement|\PDOStatement $stmt): void
    {
        if (empty($this->sqlParts['binds'])) {
            return;
        }
        $method = method_exists($stmt, 'bindTypedValue') ? 'bindTypedValue' : 'bindValue';
        foreach ($this->sqlParts['binds'] as $bind) {
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
