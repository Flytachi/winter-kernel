<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request;

use RuntimeException;

/**
 * Thrown when request data is invalid or required fields are missing.
 * Router catches it and responds with the given HTTP status code (default 400).
 */
class RequestException extends RuntimeException
{
    public function __construct(string $message, int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** @throws static */
    public static function throw(string $message, int $code = 400, ?\Throwable $previous = null): never
    {
        throw new static($message, $code, $previous);
    }
}
