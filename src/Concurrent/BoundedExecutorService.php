<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent;

/**
 * An {@see ExecutorService} that caps how many tasks run at once, and can report
 * its live occupancy.
 *
 * Mirrors the introspection of `java.util.concurrent.ThreadPoolExecutor`
 * (`getActiveCount()`, `getQueue().size()`). The counters are meaningful under a
 * runtime with real concurrency (Swoole coroutines); without one the pool runs
 * tasks sequentially and the gauges stay at rest.
 *
 * @see Executors::newFixedExecutor()
 */
interface BoundedExecutorService extends ExecutorService
{
    /** Maximum tasks allowed to run at the same time. */
    public function concurrency(): int;

    /** Tasks currently executing (0..concurrency). */
    public function activeCount(): int;

    /** Tasks accepted but waiting for a free slot. */
    public function queuedCount(): int;

    /** How many more tasks can be accepted before the reject policy kicks in ({@see PHP_INT_MAX} when unbounded). */
    public function remainingCapacity(): int;
}
