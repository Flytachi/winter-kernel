<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Concurrent;

/**
 * Thrown by {@see Future::get()} when the given timeout elapsed before the task completed.
 *
 * The task itself keeps running; the timeout only bounds the wait.
 */
class TimeoutException extends \RuntimeException
{
}
