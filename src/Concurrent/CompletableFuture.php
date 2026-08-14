<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent;

/**
 * A {@see Future} whose completion can be driven explicitly.
 *
 * Mirrors `java.util.concurrent.CompletableFuture`. The object owns only the
 * result state; the mechanics of *waiting* are supplied by the executor that
 * created it through {@see setAwaiter()}. That keeps this class free of any
 * runtime-specific code — the very same instance is used under Swoole and
 * under FPM.
 *
 * Two roles are supported:
 *
 * - **Producer** — the executor (or an `#[Async]` method body) calls
 *   {@see complete()} or {@see completeExceptionally()} exactly once.
 * - **Consumer** — application code calls {@see get()} / {@see join()}.
 *
 * ---
 * ### Example — completing manually
 *
 * ```
 * #[Async]
 * public function send(int $id): Future
 * {
 *     $result = $this->api->call($id);
 *     return CompletableFuture::completedFuture($result);
 * }
 * ```
 *
 * @see Future
 * @see ExecutorService
 *
 * @link https://winterframe.net/docs/async Completing a promise by hand
 */
final class CompletableFuture implements Future
{
    private const int STATE_PENDING = 0;
    private const int STATE_COMPLETED = 1;
    private const int STATE_FAILED = 2;
    private const int STATE_CANCELLED = 3;

    private int $state = self::STATE_PENDING;
    private mixed $value = null;
    private ?\Throwable $throwable = null;

    /**
     * Backend-specific blocking strategy, invoked by {@see get()} while the
     * future is still pending. Receives the remaining timeout in seconds.
     *
     * @var (\Closure(float|null): void)|null
     */
    private ?\Closure $awaiter = null;

    /**
     * Backend-specific interruption strategy, invoked by {@see cancel()}.
     *
     * @var (\Closure(): void)|null
     */
    private ?\Closure $canceller = null;

    /** @var list<\Closure(mixed, \Throwable|null): void> */
    private array $listeners = [];

    // -------------------------------------------------------------------------
    // Factories
    // -------------------------------------------------------------------------

    /**
     * Returns a future that is already completed with the given value.
     */
    public static function completedFuture(mixed $value): self
    {
        $future = new self();
        $future->complete($value);

        return $future;
    }

    /**
     * Returns a future that has already failed with the given throwable.
     */
    public static function failedFuture(\Throwable $throwable): self
    {
        $future = new self();
        $future->completeExceptionally($throwable);

        return $future;
    }

    /**
     * Submits a value-returning task to an executor.
     *
     * @param callable $supplier Task producing the result.
     * @param ExecutorService|null $executor Executor to run on; defaults to {@see Executors::common()}.
     */
    public static function supplyAsync(callable $supplier, ?ExecutorService $executor = null): Future
    {
        return ($executor ?? Executors::common())->submit($supplier);
    }

    /**
     * Submits a task whose result is discarded.
     *
     * @param callable $runnable Task to run.
     * @param ExecutorService|null $executor Executor to run on; defaults to {@see Executors::common()}.
     */
    public static function runAsync(callable $runnable, ?ExecutorService $executor = null): Future
    {
        return ($executor ?? Executors::common())->submit(static function () use ($runnable): null {
            $runnable();

            return null;
        });
    }

    /**
     * Returns a future completing when every given future has completed.
     *
     * The result is always null. If any input fails or is cancelled, the
     * returned future fails with that same throwable.
     *
     * @param Future ...$futures Futures to await.
     */
    public static function allOf(Future ...$futures): Future
    {
        $all = new self();
        $all->setAwaiter(static function (?float $timeout) use ($futures, $all): void {
            $deadline = $timeout === null ? null : microtime(true) + $timeout;

            foreach ($futures as $future) {
                try {
                    $future->get($deadline === null ? null : max(0.0, $deadline - microtime(true)));
                } catch (\Throwable $throwable) {
                    $all->completeExceptionally($throwable);

                    return;
                }
            }

            $all->complete(null);
        });

        return $all;
    }

    // -------------------------------------------------------------------------
    // Producer side
    // -------------------------------------------------------------------------

    /**
     * Completes the future with a value.
     *
     * @return bool False if the future was already settled.
     */
    public function complete(mixed $value): bool
    {
        if ($this->state !== self::STATE_PENDING) {
            return false;
        }

        $this->value = $value;
        $this->state = self::STATE_COMPLETED;
        $this->settle();

        return true;
    }

    /**
     * Completes the future with a throwable.
     *
     * @return bool False if the future was already settled.
     */
    public function completeExceptionally(\Throwable $throwable): bool
    {
        if ($this->state !== self::STATE_PENDING) {
            return false;
        }

        $this->throwable = $throwable;
        $this->state = self::STATE_FAILED;
        $this->settle();

        return true;
    }

    /**
     * Registers the blocking strategy used while the future is pending.
     *
     * Called by the executor that created the future. The closure must return
     * once the future is settled or the timeout has elapsed.
     *
     * @internal
     * @param \Closure(float|null): void $awaiter Blocking strategy.
     */
    public function setAwaiter(\Closure $awaiter): void
    {
        $this->awaiter = $awaiter;
    }

    /**
     * Registers the interruption strategy used by {@see cancel()}.
     *
     * @internal
     * @param \Closure(): void $canceller Interruption strategy.
     */
    public function setCanceller(\Closure $canceller): void
    {
        $this->canceller = $canceller;
    }

    /**
     * Registers a callback fired once the future settles.
     *
     * The callback receives the value and the throwable; exactly one of them is
     * null. If the future has already settled, the callback runs immediately.
     *
     * Unlike Java this returns the same instance rather than a new stage — the
     * composition pipeline (`thenApply` and friends) is intentionally omitted.
     *
     * @param \Closure(mixed, \Throwable|null): void $action Completion callback.
     */
    public function whenComplete(\Closure $action): self
    {
        if ($this->state === self::STATE_PENDING) {
            $this->listeners[] = $action;

            return $this;
        }

        $action($this->value, $this->failure());

        return $this;
    }

    // -------------------------------------------------------------------------
    // Consumer side
    // -------------------------------------------------------------------------

    public function get(?float $timeout = null): mixed
    {
        if ($this->state === self::STATE_PENDING && $this->awaiter !== null) {
            ($this->awaiter)($timeout);
        }

        return match ($this->state) {
            self::STATE_COMPLETED => $this->value,
            self::STATE_FAILED => throw new ExecutionException($this->throwable),
            self::STATE_CANCELLED => throw new CancellationException('Task was cancelled'),
            default => throw new TimeoutException(
                $timeout === null
                    ? 'Future is still pending and no awaiter is attached'
                    : sprintf('Future did not complete within %.3f second(s)', $timeout)
            ),
        };
    }

    /**
     * Waits indefinitely and returns the result.
     *
     * Convenience alias of `get(null)`.
     */
    public function join(): mixed
    {
        return $this->get();
    }

    public function isDone(): bool
    {
        return $this->state !== self::STATE_PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->state === self::STATE_CANCELLED;
    }

    /**
     * Returns true when the task finished by throwing.
     */
    public function isCompletedExceptionally(): bool
    {
        return $this->state === self::STATE_FAILED;
    }

    /**
     * Returns the throwable the task failed with, or null.
     */
    public function failure(): ?\Throwable
    {
        return $this->throwable;
    }

    public function cancel(bool $mayInterruptIfRunning = false): bool
    {
        if ($this->state !== self::STATE_PENDING) {
            return false;
        }

        $this->state = self::STATE_CANCELLED;

        if ($mayInterruptIfRunning && $this->canceller !== null) {
            ($this->canceller)();
        }

        $this->settle();

        return true;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Releases waiters and fires completion callbacks exactly once.
     */
    private function settle(): void
    {
        $listeners = $this->listeners;
        $this->listeners = [];
        $this->awaiter = null;
        $this->canceller = null;

        foreach ($listeners as $listener) {
            $listener($this->value, $this->throwable);
        }
    }
}
