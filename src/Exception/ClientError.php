<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Exception;

use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;

/**
 * Client-side business error — signals a domain violation caused by the caller.
 *
 * Defaults to 409 Conflict; override the constructor code for other 4xx codes.
 * Logged at WARNING level (expected, not a server problem).
 *
 * Example:
 *   throw new ClientError('Email already taken');
 *   ClientError::throw('Email already taken', HttpCode::UNPROCESSABLE_ENTITY);
 */
class ClientError extends \RuntimeException implements LogLevelException
{
    use ExceptionTrait;

    protected $code = HttpCode::CONFLICT->value;

    public function getLogLevel(): string
    {
        return LogLevel::WARNING;
    }
}
