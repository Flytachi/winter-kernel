<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent;

/**
 * Handle to the result of an asynchronous computation.
 *
 * Mirrors `java.util.concurrent.Future`. A Future is produced by
 * {@see ExecutorService::submit()} and represents a task that may still be
 * running, already finished, or cancelled.
 *
 * The runtime backend behind a Future is irrelevant to the caller: under Swoole
 * it is a coroutine, under FPM it is a deferred task executed either lazily on
 * {@see get()} or after the response has been flushed to the client.
 *
 * ---
 * ### Example
 *
 * ```
 * $future = Executors::common()->submit(fn() => $api->fetch($id));
 * $value  = $future->get();
 * ```
 *
 * @see CompletableFuture
 * @see ExecutorService
 */
interface Future
{
    /**
     * Waits for the computation to finish and returns its result.
     *
     * @param float|null $timeout Seconds to wait, or null to wait indefinitely.
     * @return mixed The value produced by the task.
     * @throws ExecutionException If the task threw; the original throwable is the previous exception.
     * @throws TimeoutException If $timeout elapsed before completion.
     * @throws CancellationException If the task was cancelled.
     */
    public function get(?float $timeout = null): mixed;

    /**
     * Returns true once the task has completed, failed or been cancelled.
     */
    public function isDone(): bool;

    /**
     * Returns true if the task was cancelled before it completed.
     */
    public function isCancelled(): bool;

    /**
     * Attempts to cancel the task.
     *
     * @param bool $mayInterruptIfRunning Whether an already running task may be interrupted.
     * @return bool True if the task was cancelled by this call.
     */
    public function cancel(bool $mayInterruptIfRunning = false): bool;
}
