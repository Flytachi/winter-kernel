<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\Base\Exception\ExceptionHeader;
use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Exception\ExceptionHeaderTrait;
use Psr\Log\LogLevel;

/**
 * Throwable HTTP response exception.
 *
 * Throw from anywhere (controller, service, middleware) — the Router
 * catches it and sends a proper HTTP response automatically.
 *
 * Examples:
 *   throw new ResponseException('Not found', HttpCode::NOT_FOUND);
 *   ResponseException::throw('Forbidden', HttpCode::FORBIDDEN);
 */
class ResponseException extends \RuntimeException implements ExceptionLogLevel, ExceptionHeader
{
    use ExceptionTrait;
    use ExceptionHeaderTrait;

    protected $code = HttpCode::BAD_REQUEST->value;

    public function getLogLevel(): string
    {
        return LogLevel::WARNING;
    }
}
