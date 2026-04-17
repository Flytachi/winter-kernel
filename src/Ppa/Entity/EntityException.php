<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Entity;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\K2\Exception\LogLevelException;
use Psr\Log\LogLevel;

/**
 * Thrown when a required entity is not found or violates a domain rule.
 * Logged at WARNING level (expected, caller-caused).
 */
class EntityException extends Exception implements LogLevelException
{
    public function getLogLevel(): string
    {
        return LogLevel::WARNING;
    }
}
