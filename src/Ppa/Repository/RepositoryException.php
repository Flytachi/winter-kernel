<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Repository;

use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Psr\Log\LogLevel;

/**
 * Thrown when a repository operation fails at the infrastructure level.
 * Logged at ALERT level.
 */
class RepositoryException extends \RuntimeException implements ExceptionLogLevel
{
    use ExceptionTrait;

    public function getLogLevel(): string
    {
        return LogLevel::ALERT;
    }
}
