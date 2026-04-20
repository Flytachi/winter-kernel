<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Response\ResponseException;

/**
 * Thrown when request data is invalid or required fields are missing.
 * Router catches it and responds with the given HTTP status code (default 400).
 *
 * Inherits ::throw() and withHeader() from ResponseException.
 *
 * Example:
 *   throw new RequestException('Missing required field: email');
 *   RequestException::throw('Invalid UUID format', HttpCode::UNPROCESSABLE_ENTITY);
 */
class RequestException extends ResponseException
{
    public function __construct(
        string $message = '',
        HttpCode $httpCode = HttpCode::BAD_REQUEST,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpCode, $previous);
    }
}
