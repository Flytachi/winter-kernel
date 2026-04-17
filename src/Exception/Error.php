<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Exception;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;

/**
 * Generic HTTP-aware exception — resolves its log level from the HTTP code.
 *
 * | Code range  | Log level |
 * |-------------|-----------|
 * | 4xx         | WARNING   |
 * | 5xx / 520+  | ERROR     |
 * | Unknown     | CRITICAL  |
 * | Other       | NOTICE    |
 *
 * Example:
 *   throw new Error('Not implemented', HttpCode::NOT_IMPLEMENTED->value);
 *   Error::throw('Not implemented', HttpCode::NOT_IMPLEMENTED);
 */
class Error extends Exception implements LogLevelException
{
    protected $code = HttpCode::UNKNOWN_ERROR->value;

    private string $resolvedLogLevel;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $httpCode = HttpCode::tryFrom($code ?: $this->code);

        $this->resolvedLogLevel = match (true) {
            $httpCode === null          => LogLevel::CRITICAL,
            $httpCode->isServerError()  => LogLevel::ERROR,
            $httpCode->isClientError()  => LogLevel::WARNING,
            default                     => LogLevel::NOTICE,
        };

        parent::__construct($message, $code, $previous);
    }

    public function getLogLevel(): string
    {
        return $this->resolvedLogLevel;
    }
}
