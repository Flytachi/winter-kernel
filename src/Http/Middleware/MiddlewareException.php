<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Middleware;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Response\ResponseException;

/**
 * Throw from any middleware to abort the request with a specific HTTP status.
 * Default: 401 Unauthorized (the most common middleware rejection).
 *
 * Inherits ::throw() and withHeader() from ResponseException.
 *
 * Example:
 *   throw new MiddlewareException('Token expired');
 *   MiddlewareException::throw('Forbidden', HttpCode::FORBIDDEN);
 *   throw (new MiddlewareException('Rate limited', HttpCode::TOO_MANY_REQUESTS))
 *       ->withHeader('Retry-After', '60');
 */
class MiddlewareException extends ResponseException
{
    public function __construct(
        string $message = '',
        HttpCode|string|int $httpCode = HttpCode::UNAUTHORIZED,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpCode, $previous);
    }
}
