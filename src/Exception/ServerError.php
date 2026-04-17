<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Exception;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;

/**
 * Server-side failure — signals an unexpected infrastructure or logic problem.
 *
 * Defaults to 500 Internal Server Error.
 * Logged at ERROR level.
 *
 * Example:
 *   throw new ServerError('Payment gateway timeout');
 *   ServerError::throw('Payment gateway timeout');
 */
class ServerError extends Exception implements LogLevelException
{
    protected $code = HttpCode::INTERNAL_SERVER_ERROR->value;

    public function getLogLevel(): string
    {
        return LogLevel::ERROR;
    }
}
