<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent;

/**
 * Thrown by {@see Future::get()} when the task terminated with a throwable.
 *
 * The original throwable is always available through `getPrevious()`.
 */
class ExecutionException extends \RuntimeException
{
    public function __construct(\Throwable $cause)
    {
        parent::__construct(
            $cause::class . ': ' . $cause->getMessage(),
            (int) $cause->getCode(),
            $cause
        );
    }
}
