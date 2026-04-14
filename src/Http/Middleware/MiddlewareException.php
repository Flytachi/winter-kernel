<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Middleware;

use Flytachi\Winter\Base\HttpCode;

/**
 * Throw from any middleware to abort the request with a specific HTTP status.
 *
 * Default: 401 Unauthorized (the most common middleware rejection).
 *
 * Example:
 *   throw new MiddlewareException('Token expired', HttpCode::UNAUTHORIZED);
 */
class MiddlewareException extends \RuntimeException
{
    public function __construct(
        string           $message  = '',
        private HttpCode $httpCode = HttpCode::UNAUTHORIZED,
        ?\Throwable      $previous = null,
    ) {
        parent::__construct($message, $httpCode->value, $previous);
    }

    public function getHttpCode(): HttpCode
    {
        return $this->httpCode;
    }
}
