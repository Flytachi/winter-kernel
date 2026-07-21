<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Concurrent;

/**
 * Thrown by {@see Future::get()} when the task was cancelled before it produced a result.
 */
class CancellationException extends \RuntimeException
{
}
