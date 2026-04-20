<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Operation;

/**
 * Immutable value object carrying the outcome of a background operation.
 *
 * Holds either the return value of the executed callback, the {@see \Throwable}
 * it threw, or both (result = null, throwable = exception).
 *
 * OpResult instances are created exclusively by {@see OperationRunnable::run()}
 * inside the child process and read by {@see Future::get()} in the parent.
 *
 * ---
 * ### Example
 *
 * ```
 * $opResult = $future->get();
 *
 * if ($opResult->getThrowable() !== null) {
 *     // task failed
 *     echo $opResult->getThrowable()->getMessage();
 * } else {
 *     $value = $opResult->getResult();
 * }
 * ```
 * @template TResult
 *
 * @see Future::get()
 * @see OperationRunnable::run()
 */
readonly class OpResult
{
    /**
     * @param TResult         $result    The value returned by the callback.
     * @param \Throwable|null $throwable The exception thrown by the callback, or null on success.
     */
    public function __construct(
        private mixed $result,
        private ?\Throwable $throwable
    ) {
    }

    /** @return TResult */
    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getThrowable(): ?\Throwable
    {
        return $this->throwable;
    }
}
