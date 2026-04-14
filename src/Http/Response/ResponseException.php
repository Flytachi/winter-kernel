<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\Base\HttpCode;

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
class ResponseException extends \RuntimeException
{
    private array $extraHeaders = [];

    public function __construct(
        string           $message  = '',
        private HttpCode $httpCode = HttpCode::INTERNAL_SERVER_ERROR,
        ?\Throwable      $previous = null,
    ) {
        parent::__construct($message, $httpCode->value, $previous);
    }

    public function getHttpCode(): HttpCode
    {
        return $this->httpCode;
    }

    public function withHeader(string $name, string $value): static
    {
        $this->extraHeaders[$name] = $value;
        return $this;
    }

    public function getExtraHeaders(): array
    {
        return $this->extraHeaders;
    }

    /** @throws static */
    public static function throw(
        string      $message  = '',
        HttpCode    $httpCode = HttpCode::INTERNAL_SERVER_ERROR,
        ?\Throwable $previous = null,
    ): never {
        throw new static($message, $httpCode, $previous);
    }
}
