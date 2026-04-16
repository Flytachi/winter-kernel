<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Repository;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

/**
 * Exception thrown when a repository operation fails at the infrastructure level.
 *
 * Logged at ALERT level. Wraps lower-level {@see \Flytachi\Winter\Cdo\Connection\CDOException}
 * or PDO exceptions to provide a unified exception surface for repository consumers.
 * Also thrown directly for invalid query builder usage (e.g. duplicate FROM, missing alias).
 *
 * @see RepositoryCore::buildSql()
 * @see RepositoryCrudTrait
 * @see RepositoryViewTrait
 */
class RepositoryException extends Exception
{
    protected string $logLevel = LogLevel::ALERT;
}
