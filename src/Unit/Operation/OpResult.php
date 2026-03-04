<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Operation;

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
 *     // task succeeded
 *     $value = $opResult->getResult();
 * }
 * ```
 * @template TResult
 *
 * @package Flytachi\Winter\Kernel\Unit\Operation
 * @see Future::get()
 * @see OperationRunnable::run()
 */
readonly class OpResult
{
    /**
     * Creates a new OpResult.
     *
     * @param TResult         $result    The value returned by the callback.
     *                                   Null if the callback returned void or threw an exception.
     * @param \Throwable|null $throwable The exception or error thrown by the callback.
     *                                   Null if execution completed without throwing.
     */
    public function __construct(
        private mixed $result,
        private ?\Throwable $throwable
    ) {
    }

    /**
     * Returns the callback's return value.
     *
     * @return TResult The return value, or null if the callback returned void or threw an exception.
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * Returns the exception thrown by the callback, if any.
     *
     * @return \Throwable|null The caught exception or error, or null if the task succeeded.
     */
    public function getThrowable(): ?\Throwable
    {
        return $this->throwable;
    }
}
