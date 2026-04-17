<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Repository;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\K2\Exception\LogLevelException;
use Psr\Log\LogLevel;

/**
 * Thrown when a repository operation fails at the infrastructure level.
 * Logged at ALERT level.
 */
class RepositoryException extends Exception implements LogLevelException
{
    public function getLogLevel(): string
    {
        return LogLevel::ALERT;
    }
}
