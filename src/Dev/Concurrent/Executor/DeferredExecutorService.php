<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Concurrent\Executor;

use Flytachi\Winter\K2\Dev\Concurrent\CompletableFuture;
use Flytachi\Winter\K2\Dev\Concurrent\ExecutorService;
use Flytachi\Winter\K2\Dev\Concurrent\Future;
use Flytachi\Winter\K2\Dev\Concurrent\RejectedExecutionException;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * Executor for runtimes without coroutines — PHP-FPM, CLI, the built-in server.
 *
 * There is no concurrency to be had in a synchronous SAPI, so the contract is
 * preserved by moving the work in time rather than running it in parallel:
 *
 * - {@see submit()} runs the task lazily, at the moment its future is awaited.
 *   A future that is never awaited still runs — during the drain below.
 * - {@see execute()} always defers. The queue is drained after
 *   `fastcgi_finish_request()` has flushed the response, so the client never
 *   waits for it.
 * - {@see invokeAll()} runs everything right away, in order.
 *
 * Deferred tasks execute **sequentially in the same worker**: four tasks of
 * 200 ms occupy the worker for 800 ms after the response was sent. That is the
 * deliberate trade — no process spawning, no closure serialization, no extra
 * database connection. When genuine parallelism is required under FPM, ask for
 * a process-backed executor explicitly.
 *
 * Note that `max_execution_time` and FPM's `request_terminate_timeout` keep
 * counting during the drain: a long deferred task can still be killed.
 *
 * @see \Flytachi\Winter\K2\Dev\Concurrent\Executors
 */
final class DeferredExecutorService implements ExecutorService
{
    /** @var array<int, \Closure(): void> */
    private array $queue = [];

    private int $sequence = 0;
    private bool $shutdown = false;
    private bool $draining = false;
    private bool $hooked = false;

    public function submit(callable $task, mixed ...$args): Future
    {
        $this->ensureAccepting();

        $future = new CompletableFuture();
        $run = $this->runner($task, $args, $future);
        $id = $this->enqueue(static fn() => $run(true));

        // Awaiting runs the task right here, so its queue slot is no longer needed.
        $future->setAwaiter(function (?float $timeout) use ($run, $id): void {
            unset($this->queue[$id]);
            $run(false);
        });

        return $future;
    }

    public function execute(callable $task, mixed ...$args): void
    {
        $this->ensureAccepting();

        $run = $this->runner($task, $args, new CompletableFuture());
        $this->enqueue(static fn() => $run(true));
    }

    public function invokeAll(iterable $tasks, ?float $timeout = null): array
    {
        $futures = [];
        foreach ($tasks as $task) {
            $future = $this->submit($task);

            try {
                $future->get();
            } catch (\Throwable) {
                // The outcome stays on the future; the caller inspects it there.
            }

            $futures[] = $future;
        }

        return $futures;
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
        $this->drain();
    }

    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    public function awaitTermination(?float $timeout = null): bool
    {
        $this->drain();

        return true;
    }

    /**
     * Returns the number of tasks still waiting to be drained.
     */
    public function pendingCount(): int
    {
        return count($this->queue);
    }

    /**
     * Flushes the response and runs every queued task.
     *
     * Registered as a shutdown function on the first enqueue, and safe to call
     * manually at any point.
     */
    public function drain(): void
    {
        if ($this->draining || $this->queue === []) {
            return;
        }

        $this->draining = true;
        $this->releaseClient();

        try {
            while ($this->queue !== []) {
                $id = array_key_first($this->queue);
                $task = $this->queue[$id];
                unset($this->queue[$id]);
                $task();
            }
        } finally {
            $this->draining = false;
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Builds the single runner shared by the lazy path and the drain path.
     *
     * The flag tells the runner whether nobody is holding the future any more,
     * in which case a failure would otherwise vanish and must be logged.
     *
     * @param callable $task Task to run.
     * @param list<mixed> $args Arguments passed to the task.
     * @param CompletableFuture $future Future carrying the outcome.
     * @return \Closure(bool): void
     */
    private function runner(callable $task, array $args, CompletableFuture $future): \Closure
    {
        return function (bool $unobserved) use ($task, $args, $future): void {
            if ($future->isDone()) {
                return;
            }

            try {
                $future->complete($task(...$args));
            } catch (\Throwable $throwable) {
                $future->completeExceptionally($throwable);
                if ($unobserved) {
                    $this->logFailure($throwable);
                }
            }
        };
    }

    /**
     * Queues a task and makes sure the drain will happen at shutdown.
     *
     * @param \Closure(): void $task Task to queue.
     * @return int Slot identifier, used to drop the task once it has run early.
     */
    private function enqueue(\Closure $task): int
    {
        $id = $this->sequence++;
        $this->queue[$id] = $task;

        if (!$this->hooked) {
            $this->hooked = true;
            register_shutdown_function($this->drain(...));
        }

        return $id;
    }

    /**
     * Sends the response before the queue runs, when the SAPI allows it.
     *
     * Only PHP-FPM can do this. Under CLI and the built-in server the client
     * simply receives the response once the script ends.
     */
    private function releaseClient(): void
    {
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
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

    /**
     * Reports a failure nobody is able to observe through a future.
     */
    private function logFailure(\Throwable $throwable): void
    {
        LoggerFactory::getLogger(self::class)->error(
            $throwable->getMessage()
            . (env('DEBUG', false) ? "\n" . $throwable->getTraceAsString() : '')
        );
    }
}
