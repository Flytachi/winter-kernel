<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Kernel\Concurrent\Executor\CoroutineExecutorService;
use Flytachi\Winter\Kernel\Concurrent\Executor\DeferredExecutorService;
use Flytachi\Winter\Kernel\Concurrent\Executor\FixedExecutorService;

/**
 * Factory for {@see ExecutorService} instances.
 *
 * Mirrors `java.util.concurrent.Executors`, plus a shared instance in the
 * spirit of `ForkJoinPool.commonPool()`.
 *
 * The framework never picks a strategy behind your back beyond
 * {@see common()}, which simply matches the current runtime. Anything else —
 * for instance forcing real parallelism under FPM — is an explicit choice made
 * at the call site.
 *
 * ---
 * ### Example
 *
 * ```
 * // Adapts to the runtime: coroutines under Swoole, deferred under FPM.
 * Executors::common()->execute(fn() => $mixpanel->track($userId, $event));
 *
 * // Force a specific backend.
 * $executor = Executors::newDeferredExecutor();
 * ```
 *
 * @see ExecutorService
 */
final class Executors
{
    private static ?CoroutineExecutorService $coroutine = null;
    private static ?DeferredExecutorService $deferred = null;

    private function __construct()
    {
    }

    /**
     * Returns the shared executor matching the current runtime.
     *
     * Inside a Swoole coroutine this is the coroutine executor; everywhere else
     * it is the deferred executor. The decision is made per call, never cached,
     * so the same process may legitimately use both — for example a Swoole
     * worker booting outside of a coroutine and then serving requests inside
     * one.
     */
    public static function common(): ExecutorService
    {
        return Runtime::isSwooleCoroutine()
            ? self::coroutine()
            : self::deferred();
    }

    /**
     * Returns a fresh coroutine-backed executor.
     *
     * Requires an active Swoole coroutine at submit time.
     */
    public static function newCoroutineExecutor(): ExecutorService
    {
        return new CoroutineExecutorService();
    }

    /**
     * Returns a fresh executor that defers tasks until the response is flushed.
     */
    public static function newDeferredExecutor(): ExecutorService
    {
        return new DeferredExecutorService();
    }

    /**
     * Returns a fresh fixed-size pool: at most $concurrency tasks run at once.
     *
     * Mirrors `Executors.newFixedThreadPool(n)`. The bound is enforced under Swoole
     * (coroutine semaphore); without coroutines the pool runs tasks sequentially
     * (deferred), where the bound is a no-op. Register the result in the container
     * to back an `#[Async('id')]` method with a dedicated, capped pool.
     *
     * ```
     * $c->singleton('mailPool', fn() => Executors::newFixedExecutor(5));
     * // then: #[Async('mailPool')] public function send(): void { ... }
     * ```
     *
     * @param int $concurrency Maximum simultaneous tasks (>= 1).
     * @param int $queue Waiting slots before the reject policy applies; 0 = unbounded (never rejects).
     * @param RejectPolicy $onReject What to do with a task when a bounded queue is full.
     */
    public static function newFixedExecutor(
        int $concurrency,
        int $queue = 0,
        RejectPolicy $onReject = RejectPolicy::ABORT,
    ): BoundedExecutorService {
        return new FixedExecutorService($concurrency, $queue, $onReject);
    }

    /**
     * Drains the shared executors and stops them accepting new tasks.
     *
     * Intended for graceful shutdown — worker recycling under Swoole, or an
     * explicit flush at the end of a console command.
     *
     * @param float|null $timeout Seconds to wait for running tasks, or null for no limit.
     * @return bool True if everything settled within the timeout.
     */
    public static function shutdownCommon(?float $timeout = null): bool
    {
        $drained = true;

        foreach ([self::$coroutine, self::$deferred] as $executor) {
            if ($executor === null) {
                continue;
            }

            $executor->shutdown();
            $drained = $executor->awaitTermination($timeout) && $drained;
        }

        return $drained;
    }

    private static function coroutine(): CoroutineExecutorService
    {
        return self::$coroutine ??= new CoroutineExecutorService();
    }

    private static function deferred(): DeferredExecutorService
    {
        return self::$deferred ??= new DeferredExecutorService();
    }
}
