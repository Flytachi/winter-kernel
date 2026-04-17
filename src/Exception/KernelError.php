<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Exception;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;

/**
 * Framework-internal failure — signals a bug in the kernel logic itself.
 *
 * Use this for invariant violations, misconfiguration, or any state
 * that should never occur in correct kernel code.
 * Logged at EMERGENCY level — highest severity.
 *
 * Example:
 *   throw new KernelError('Router not initialized before handle()');
 *   KernelError::throw('Mapping scan failed: no controllers found');
 */
class KernelError extends Exception implements LogLevelException
{
    protected $code = HttpCode::INTERNAL_SERVER_ERROR->value;

    public function getLogLevel(): string
    {
        return LogLevel::EMERGENCY;
    }
}
