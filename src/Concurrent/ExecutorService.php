<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent;

/**
 * Runs tasks asynchronously and hands back {@see Future} handles.
 *
 * Mirrors `java.util.concurrent.ExecutorService`. Implementations differ only
 * in *how* a task is carried out — a Swoole coroutine, a deferred callback run
 * after the response is flushed, or a child process — while the contract stays
 * identical, so application code never branches on the runtime.
 *
 * ---
 * ### Choosing a method
 *
 * | Need | Method |
 * |------|--------|
 * | Fire-and-forget, result irrelevant | {@see execute()} |
 * | Result needed later | {@see submit()} |
 * | Several tasks, all results needed | {@see invokeAll()} |
 *
 * ---
 * ### Example
 *
 * ```
 * $executor = Executors::common();
 *
 * $executor->execute(fn() => $mixpanel->track($userId, $event));
 *
 * $future = $executor->submit($api->fetch(...), $id);
 * $value  = $future->get();
 * ```
 *
 * @see Executors
 * @see Future
 */
interface ExecutorService
{
    /**
     * Submits a task and returns a handle to its future result.
     *
     * The task is guaranteed to run even if the returned future is never
     * awaited.
     *
     * @param callable $task Task to run.
     * @param mixed ...$args Arguments passed to the task.
     * @throws RejectedExecutionException If the executor cannot accept the task.
     */
    public function submit(callable $task, mixed ...$args): Future;

    /**
     * Submits a task whose result and return value are discarded.
     *
     * Failures are logged rather than propagated — nobody is holding a handle
     * to observe them.
     *
     * @param callable $task Task to run.
     * @param mixed ...$args Arguments passed to the task.
     * @throws RejectedExecutionException If the executor cannot accept the task.
     */
    public function execute(callable $task, mixed ...$args): void;

    /**
     * Submits every task and blocks until all of them have settled.
     *
     * @param iterable<callable> $tasks Tasks to run.
     * @param float|null $timeout Seconds to wait for the whole batch, or null for no limit.
     * @return list<Future> Futures in the order the tasks were given.
     * @throws RejectedExecutionException If the executor cannot accept the tasks.
     */
    public function invokeAll(iterable $tasks, ?float $timeout = null): array;

    /**
     * Stops accepting new tasks; already submitted ones still run to completion.
     */
    public function shutdown(): void;

    /**
     * Returns true once {@see shutdown()} has been called.
     */
    public function isShutdown(): bool;

    /**
     * Blocks until every submitted task has settled.
     *
     * @param float|null $timeout Seconds to wait, or null for no limit.
     * @return bool True if the executor drained, false if the timeout elapsed first.
     */
    public function awaitTermination(?float $timeout = null): bool;
}
