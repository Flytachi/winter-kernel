<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Entity;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

/**
 * Exception thrown when a required entity is not found or violates a domain rule.
 *
 * Logged at WARNING level. Typically thrown with an HTTP 4xx status code
 * (e.g. {@see \Flytachi\Winter\Base\HttpCode::NOT_FOUND}) when a record lookup
 * fails or a precondition is unmet.
 *
 * @see RepositoryViewInterface::findByIdOrThrow()
 * @see RepositoryViewInterface::findByOrThrow()
 */
class EntityException extends Exception
{
    protected string $logLevel = LogLevel::WARNING;
}
