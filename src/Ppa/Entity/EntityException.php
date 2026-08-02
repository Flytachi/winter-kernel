<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Entity;

use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * Thrown when a required entity is not found or violates a domain rule.
 * Logged at WARNING level (expected, caller-caused).
 */
class EntityException extends RuntimeException implements ExceptionLogLevel
{
    use ExceptionTrait;

    public function getLogLevel(): string
    {
        return LogLevel::WARNING;
    }
}
