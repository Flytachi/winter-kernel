<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Repository;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Ppa\Entity\EntityException;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryViewInterface;
use PDO;

/**
 * Provides concrete read-operation implementations for repository classes.
 *
 * Implements {@see RepositoryViewInterface} by building SQL via {@see RepositoryCore},
 * executing it through CDO, and hydrating results into the configured entity class.
 * All methods call {@see cleanCache()} after execution to reset the query builder state.
 *
 * Mix into any {@see RepositoryCore} subclass that needs read access:
 * ```
 * class UserRepository extends RepositoryCore implements RepositoryViewInterface
 * {
 *     use RepositoryViewTrait;
 * }
 * ```
 *
 * @mixin RepositoryViewInterface
 */
trait RepositoryViewTrait
{
    /**
     * Executes a raw SQL query with explicit binds and returns hydrated objects.
     *
     * @param string      $sql             Raw SQL string with named placeholders
     * @param CDOBind[]   $binds           Array of {@see CDOBind} objects
     * @param string|null $entityClassName Override entity class for hydration; null uses repository default
     * @return array<object> Array of hydrated objects
     * @throws RepositoryException
     */
    final public function rawFetch(string $sql, array $binds = [], ?string $entityClassName = null): array
    {
        try {
            $stmt = new CDOStatement($this->db()->prepare($sql));
            foreach ($binds as $bind) {
                $stmt->bindTypedValue($bind->getName(), $bind->getValue());
            }
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->fetchAll(
                PDO::FETCH_CLASS,
                $entityClassName ?: $this->state()->entityClassName
            );
        } catch (\Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Executes the built query and returns the first matching row, or null.
     *
     * Automatically applies `LIMIT 1`. Calls {@see cleanCache()} after execution.
     *
     * @param string|null $entityClassName Override entity class for hydration
     * @return object|null First matching entity, or null
     * @throws RepositoryException
     */
    final public function find(?string $entityClassName = null): ?object
    {
        try {
            $state = $this->state();
            if ($entityClassName) {
                $state->entityClassName = $entityClassName;
            }
            $this->limit(1);
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            $resolvedClass = $entityClassName ?: $state->entityClassName;
            $this->cleanCache();
            return $stmt->getStmt()->fetchObject($resolvedClass) ?: null;
        } catch (\Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Executes the built query and returns a single column value from the first row.
     *
     * Automatically applies `LIMIT 1`. Calls {@see cleanCache()} after execution.
     *
     * @param int $column Zero-based column index (default 0)
     * @return mixed Column value, or false if no row found
     * @throws RepositoryException
     */
    final public function findColumn(int $column = 0): mixed
    {
        try {
            $this->limit(1);
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            $this->cleanCache();
            return $stmt->getStmt()->fetchColumn($column);
        } catch (\Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Executes the built query and returns all matching rows.
     *
     * Calls {@see cleanCache()} after execution.
     *
     * @param string|null $entityClassName Override entity class for hydration
     * @return array<object> Array of hydrated objects
     * @throws RepositoryException
     */
    final public function findAll(?string $entityClassName = null): array
    {
        try {
            $state = $this->state();
            if ($entityClassName) {
                $state->entityClassName = $entityClassName;
            }

            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            $resolvedClass = $entityClassName ?: $state->entityClassName;
            $this->cleanCache();
            return $stmt->getStmt()->fetchAll(PDO::FETCH_CLASS, $resolvedClass);
        } catch (\Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Returns the row count for the built query using `COUNT(*)`.
     *
     * If a custom {@see select()} is already set, wraps it: `COUNT(custom_expr)`.
     * Calls {@see cleanCache()} after execution.
     *
     * @return int Row count
     * @throws RepositoryException
     */
    final public function count(): int
    {
        try {
            $state = $this->state();
            $state->sqlParts['option'] = 'COUNT(' . ($state->sqlParts['option'] ?? '*') . ')';
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            $this->cleanCache();
            return (int) $stmt->getStmt()->fetchColumn(0);
        } catch (\Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Returns true if at least one row matches the built query.
     *
     * Uses `SELECT 1 LIMIT 1` internally for efficiency.
     * Calls {@see cleanCache()} after execution.
     *
     * @return bool
     * @throws RepositoryException
     */
    final public function exists(): bool
    {
        try {
            $state = $this->state();
            $state->sqlParts['option'] = '1';
            $this->limit(1);
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            $this->cleanCache();
            return (bool) $stmt->getStmt()->fetchColumn(0);
        } catch (\Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Finds a single record by its primary key.
     *
     * Uses {@see mapIdentifierColumnName()} to determine the PK column (default: `'id'`).
     *
     * @param int|string  $id              Primary key value
     * @param string|null $entityClassName Override entity class for hydration
     * @return object|null Matching entity, or null
     * @throws RepositoryException
     */
    final public static function findById(int|string $id, ?string $entityClassName = null): ?object
    {
        $instance = new static();
        return $instance
            ->where(Qb::eq($instance->mapIdentifierColumnName(), $id))
            ->find($entityClassName);
    }

    /**
     * Finds a single record matching the given condition.
     *
     * @param Qb          $qb              WHERE condition
     * @param string|null $entityClassName Override entity class for hydration
     * @return object|null Matching entity, or null
     * @throws RepositoryException
     */
    final public static function findBy(Qb $qb, ?string $entityClassName = null): ?object
    {
        return (new static())->where($qb)->find($entityClassName);
    }

    /**
     * Finds all records matching the given condition, or all rows when $qb is null.
     *
     * @param Qb|null     $qb              WHERE condition, or null to fetch all rows
     * @param string|null $entityClassName Override entity class for hydration
     * @return array<object>
     * @throws RepositoryException
     */
    final public static function findAllBy(?Qb $qb = null, ?string $entityClassName = null): array
    {
        return (new static())
            ->where($qb)
            ->findAll($entityClassName);
    }

    /**
     * Finds a record by its primary key, or throws if not found.
     *
     * @param int|string  $id              Primary key value
     * @param string|null $entityClassName Override entity class for hydration
     * @param string      $message         Exception message when not found
     * @param HttpCode    $httpCode        HTTP status code when not found
     * @return object Matching entity (never null)
     * @throws EntityException      When the record is not found
     * @throws RepositoryException
     */
    final public static function findByIdOrThrow(
        int|string $id,
        ?string $entityClassName = null,
        string $message = 'Entity not found',
        HttpCode $httpCode = HttpCode::NOT_FOUND
    ): object {
        $obj = static::findById($id, $entityClassName);
        if (!$obj) {
            throw new EntityException($message, $httpCode->value);
        }
        return $obj;
    }

    /**
     * Finds a record matching the given condition, or throws if not found.
     *
     * @param Qb          $qb              WHERE condition
     * @param string|null $entityClassName Override entity class for hydration
     * @param string      $message         Exception message when not found
     * @param HttpCode    $httpCode        HTTP status code when not found
     * @return object Matching entity (never null)
     * @throws EntityException      When no record matches the condition
     * @throws RepositoryException
     */
    final public static function findByOrThrow(
        Qb $qb,
        ?string $entityClassName = null,
        string $message = 'Entity not found',
        HttpCode $httpCode = HttpCode::NOT_FOUND
    ): object {
        $obj = static::findBy($qb, $entityClassName);
        if (!$obj) {
            throw new EntityException($message, $httpCode->value);
        }
        return $obj;
    }
}
