<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Exception;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;

class Error extends Exception
{
    protected $code = HttpCode::UNKNOWN_ERROR->value;
    protected string $logLevel = LogLevel::ERROR;

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        $httpCode = HttpCode::tryFrom($code);
        if ($httpCode == null) {
            $this->logLevel = LogLevel::CRITICAL;
        } else {
            if ($httpCode->isServerError()) {
                $this->logLevel = LogLevel::ERROR;
            } elseif ($httpCode->isClientError()) {
                $this->logLevel = LogLevel::WARNING;
            } else {
                $this->logLevel = LogLevel::NOTICE;
            }
        }
        parent::__construct($message, $code, $previous);
    }
}
