<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent\Executor;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\K2\Concurrent\CompletableFuture;
use Flytachi\Winter\K2\Concurrent\ExecutorService;
use Flytachi\Winter\K2\Concurrent\Future;
use Flytachi\Winter\K2\Concurrent\RejectedExecutionException;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * Executor backed by Swoole coroutines.
 *
 * Every task becomes a coroutine in the current process, which makes this the
 * only backend offering real concurrency: a task blocked on I/O yields, and the
 * worker keeps serving other work. Waiting on a {@see Future} suspends the
 * calling coroutine through a `Channel` instead of blocking the process.
 *
 * Because a task runs in its own coroutine it also gets its own coroutine
 * context — meaning its own connection borrowed from the PPA pool, returned
 * automatically when the task ends. Request-scoped state (headers, locale,
 * repository query state) is deliberately **not** inherited: everything a task
 * needs must be passed through its arguments.
 *
 * Requires an active coroutine; use {@see \Flytachi\Winter\K2\Concurrent\Executors::common()}
 * to get the right backend for the current runtime.
 *
 * @see \Flytachi\Winter\K2\Concurrent\Executors
 */
final class CoroutineExecutorService implements ExecutorService
{
    /** Polling step used by {@see awaitTermination()}, in seconds. */
    private const float DRAIN_TICK = 0.001;

    private bool $shutdown = false;
    private int $running = 0;

    public function submit(callable $task, mixed ...$args): Future
    {
        $this->ensureAccepting();

        $future = new CompletableFuture();
        $this->attachAwaiter($future);
        $this->spawn($task, $args, $future, false);

        return $future;
    }

    public function execute(callable $task, mixed ...$args): void
    {
        $this->ensureAccepting();

        $this->spawn($task, $args, new CompletableFuture(), true);
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
    }

    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    /**
     * {@inheritDoc}
     *
     * Only a coroutine can wait here: outside one the scheduler is not running,
     * so busy-waiting would never let the pending tasks advance. In that case
     * the current state is reported without waiting.
     */
    public function awaitTermination(?float $timeout = null): bool
    {
        if (!Runtime::isSwooleCoroutine()) {
            return $this->running === 0;
        }

        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while ($this->running > 0) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }
            \Swoole\Coroutine::sleep(self::DRAIN_TICK);
        }

        return true;
    }

    /**
     * Returns the number of tasks that have not settled yet.
     */
    public function runningCount(): int
    {
        return $this->running;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Starts the task in its own coroutine.
     *
     * Swoole switches into the new coroutine immediately, so a task that never
     * suspends is already settled by the time this method returns.
     *
     * @param callable $task Task to run.
     * @param list<mixed> $args Arguments passed to the task.
     * @param CompletableFuture $future Future carrying the outcome.
     * @param bool $logFailure Whether a throwable must be logged instead of only stored.
     */
    private function spawn(callable $task, array $args, CompletableFuture $future, bool $logFailure): void
    {
        $this->running++;

        $cid = \Swoole\Coroutine::create(function () use ($task, $args, $future, $logFailure): void {
            $throwable = null;
            $value = null;

            try {
                $value = $task(...$args);
            } catch (\Throwable $caught) {
                $throwable = $caught;
            } finally {
                // Settling resumes the waiters, so the counter must already be
                // accurate by then — otherwise they observe a stale value.
                $this->running--;
            }

            if ($throwable === null) {
                $future->complete($value);

                return;
            }

            $future->completeExceptionally($throwable);
            if ($logFailure) {
                $this->logFailure($throwable);
            }
        });

        if ($cid === false) {
            $this->running--;
            $future->completeExceptionally(
                new RejectedExecutionException('Swoole refused to create a coroutine for the task')
            );

            throw new RejectedExecutionException('Swoole refused to create a coroutine for the task');
        }

        if (!$future->isDone()) {
            $future->setCanceller(static fn() => \Swoole\Coroutine::cancel($cid));
        }
    }

    /**
     * Wires the future to a channel so that waiting suspends the calling coroutine.
     *
     * The channel holds a single token. Whoever is woken pushes it back, so any
     * number of coroutines may wait on the same future.
     *
     * @param CompletableFuture $future Future to attach the strategy to.
     */
    private function attachAwaiter(CompletableFuture $future): void
    {
        $channel = new \Swoole\Coroutine\Channel(1);

        $future->whenComplete(static function () use ($channel): void {
            $channel->push(true);
        });

        $future->setAwaiter(static function (?float $timeout) use ($channel, $future): void {
            if ($future->isDone()) {
                return;
            }

            $token = $channel->pop($timeout ?? -1);
            if ($token !== false) {
                $channel->push($token);
            }
        });
    }

    /**
     * Rejects the task when the executor cannot run it.
     *
     * @throws RejectedExecutionException If shut down or called outside a coroutine.
     */
    private function ensureAccepting(): void
    {
        if ($this->shutdown) {
            throw new RejectedExecutionException('Executor has been shut down');
        }

        if (!Runtime::isSwooleCoroutine()) {
            throw new RejectedExecutionException(
                'CoroutineExecutorService requires an active Swoole coroutine. '
                . 'Use Executors::common() to pick the backend matching the current runtime.'
            );
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
