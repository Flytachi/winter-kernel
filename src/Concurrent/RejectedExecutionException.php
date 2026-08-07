<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent;

/**
 * Thrown when a task cannot be accepted for execution.
 *
 * Raised when the executor has been shut down, or when the executor requires a
 * runtime that is not available — for example a coroutine executor used outside
 * of an active Swoole coroutine.
 */
class RejectedExecutionException extends \RuntimeException
{
}
