<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent\Executor;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\K2\Concurrent\BoundedExecutorService;
use Flytachi\Winter\K2\Concurrent\CompletableFuture;
use Flytachi\Winter\K2\Concurrent\Future;
use Flytachi\Winter\K2\Concurrent\RejectedExecutionException;
use Flytachi\Winter\K2\Concurrent\RejectPolicy;

/**
 * A fixed-size pool: at most N tasks run concurrently, the rest wait for a slot.
 *
 * The bound is real only where the runtime has real concurrency — under Swoole,
 * where each task is a coroutine gated by a semaphore ({@see \Swoole\Coroutine\Channel})
 * of N tokens. Submitting never blocks the caller: the task's own coroutine parks
 * on the semaphore until a slot frees. Without coroutines (FPM, plain CLI) there
 * is nothing to run in parallel, so the pool delegates to the
 * {@see DeferredExecutorService} — tasks run sequentially (after the response is
 * flushed under FPM), and the concurrency bound is a no-op.
 *
 * When a bounded wait queue (`queue > 0`) is full, {@see RejectPolicy} decides the
 * outcome. An unbounded pool (`queue = 0`, the default) never rejects.
 *
 * Obtain one through {@see \Flytachi\Winter\K2\Concurrent\Executors::newFixedExecutor()};
 * register it in the container to give an `#[Async('id')]` method a dedicated pool.
 */
final class FixedExecutorService implements BoundedExecutorService
{
    private readonly CoroutineExecutorService $coroutine;
    private readonly DeferredExecutorService $deferred;
    /** Semaphore of N tokens; lazily created on first use inside a coroutine. */
    private ?\Swoole\Coroutine\Channel $slots = null;
    private int $active = 0;
    private int $queued = 0;
    private bool $shutdown = false;

    /**
     * @param int $concurrency Maximum tasks running at once (>= 1).
     * @param int $queue Waiting slots before the reject policy applies; 0 = unbounded.
     * @param RejectPolicy $onReject What to do with a task when the queue is full.
     */
    public function __construct(
        private readonly int $concurrency,
        private readonly int $queue = 0,
        private readonly RejectPolicy $onReject = RejectPolicy::ABORT,
    ) {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Fixed executor concurrency must be >= 1.');
        }
        if ($queue < 0) {
            throw new \InvalidArgumentException('Fixed executor queue capacity must be >= 0.');
        }
        $this->coroutine = new CoroutineExecutorService();
        $this->deferred = new DeferredExecutorService();
    }

    public function submit(callable $task, mixed ...$args): Future
    {
        $this->ensureAccepting();

        if (!Runtime::isSwooleCoroutine()) {
            return $this->deferred->submit($task, ...$args);
        }
        if ($this->isSaturated()) {
            return $this->reject($task, $args);
        }
        $this->queued++;

        return $this->coroutine->submit($this->gate($task, $args));
    }

    public function execute(callable $task, mixed ...$args): void
    {
        $this->ensureAccepting();

        if (!Runtime::isSwooleCoroutine()) {
            $this->deferred->execute($task, ...$args);

            return;
        }
        if ($this->isSaturated()) {
            $this->reject($task, $args);

            return;
        }
        $this->queued++;
        $this->coroutine->execute($this->gate($task, $args));
    }

    public function invokeAll(iterable $tasks, ?float $timeout = null): array
    {
        $futures = [];
        foreach ($tasks as $task) {
            $futures[] = $this->submit($task);
        }

        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        foreach ($futures as $future) {
            try {
                $future->get($deadline === null ? null : max(0.0, $deadline - microtime(true)));
            } catch (\Throwable) {
                // The outcome stays on the future; the caller inspects it there.
            }
        }

        return $futures;
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
        $this->coroutine->shutdown();
        $this->deferred->shutdown();
    }

    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    public function awaitTermination(?float $timeout = null): bool
    {
        $coroutine = $this->coroutine->awaitTermination($timeout);
        $deferred = $this->deferred->awaitTermination($timeout);

        return $coroutine && $deferred;
    }

    // -------------------------------------------------------------------------
    // Introspection
    // -------------------------------------------------------------------------

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    public function activeCount(): int
    {
        return $this->active;
    }

    public function queuedCount(): int
    {
        return $this->queued;
    }

    public function remainingCapacity(): int
    {
        if ($this->queue === 0) {
            return PHP_INT_MAX; // unbounded — never rejects
        }

        return max(0, $this->concurrency + $this->queue - ($this->active + $this->queued));
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Whether the pool is at its hard limit (only a bounded queue can saturate).
     */
    private function isSaturated(): bool
    {
        return $this->queue > 0 && ($this->active + $this->queued) >= $this->concurrency + $this->queue;
    }

    /**
     * Wraps a task so it acquires a slot before running and releases it after —
     * the coroutine parks on the semaphore while the pool is full, then advances
     * the active/queued gauges around the body.
     *
     * @param list<mixed> $args
     */
    private function gate(callable $task, array $args): \Closure
    {
        return function () use ($task, $args): mixed {
            $this->slots()->pop();
            $this->queued--;
            $this->active++;
            try {
                return $task(...$args);
            } finally {
                $this->active--;
                $this->slots()->push(true);
            }
        };
    }

    /**
     * Applies the reject policy to a task the full queue cannot accept.
     *
     * @param list<mixed> $args
     */
    private function reject(callable $task, array $args): Future
    {
        return match ($this->onReject) {
            RejectPolicy::ABORT => throw new RejectedExecutionException(
                "Fixed executor saturated (concurrency={$this->concurrency}, queue={$this->queue})."
            ),
            RejectPolicy::CALLER_RUNS => $this->runInline($task, $args),
            RejectPolicy::DISCARD => $this->discarded(),
        };
    }

    /**
     * Runs the task right here (back-pressure), reporting the outcome as a future.
     *
     * @param list<mixed> $args
     */
    private function runInline(callable $task, array $args): Future
    {
        try {
            return CompletableFuture::completedFuture($task(...$args));
        } catch (\Throwable $throwable) {
            return CompletableFuture::failedFuture($throwable);
        }
    }

    /**
     * A future for a dropped task: cancelled, so a caller can tell it never ran.
     */
    private function discarded(): Future
    {
        $future = new CompletableFuture();
        $future->cancel();

        return $future;
    }

    /**
     * The semaphore, filled with N tokens on first use (inside a coroutine).
     */
    private function slots(): \Swoole\Coroutine\Channel
    {
        if ($this->slots === null) {
            $this->slots = new \Swoole\Coroutine\Channel($this->concurrency);
            for ($i = 0; $i < $this->concurrency; $i++) {
                $this->slots->push(true);
            }
        }

        return $this->slots;
    }

    /**
     * @throws RejectedExecutionException If the executor has been shut down.
     */
    private function ensureAccepting(): void
    {
        if ($this->shutdown) {
            throw new RejectedExecutionException('Executor has been shut down');
        }
    }
}
