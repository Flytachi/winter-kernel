<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route;

use Flytachi\Winter\Base\Runtime;
use Swoole\Coroutine;
use Swoole\Timer;

/**
 * Stops the server waiting for a request that has run past its deadline.
 *
 * Swoole has no request timeout of its own. Checked against the extension itself: no
 * option in any of its lists concerns execution time, `max_request_execution_time`
 * appears nowhere in the binary, and setting it changes nothing — a handler that sleeps
 * three seconds under a one-second "limit" still answers 200 after three. So the
 * deadline is enforced here.
 *
 * ## How
 *
 * Each in-flight request registers its coroutine id and deadline; one timer per worker
 * sweeps that registry. A timer per request would cost a reactor heap entry each time,
 * while a registry entry costs one array write and one `defer` to remove it.
 *
 * The registry is also what makes the sweep safe. `Coroutine::list()` returns *every*
 * coroutine in the worker — the pool's housekeeper, telemetry, `spawn()`ed work — and
 * cancelling one of those by age would break the thing it belongs to. Only requests
 * register, so only requests are ever cancelled.
 *
 * ## What it can interrupt, and what it cannot
 *
 * `Coroutine::cancel($cid, true)` raises `CanceledException` at the point where the
 * coroutine is **waiting**. Measured: `finally` runs, `defer` runs — transactions close
 * and pooled connections return. A request that waits is therefore interrupted cleanly.
 *
 * A request burning CPU is not interrupted at all: the event loop is single-threaded,
 * so while a handler loops without yielding, this timer cannot even fire. Measured — a
 * sweep scheduled for 0.10 s woke at 1.91 s, after the 1.8 s loop it was waiting behind.
 *
 * ## Why it cancels more than once
 *
 * Cancellation is not sticky. Measured: after application code catches the
 * `CanceledException`, `Coroutine::isCanceled()` stays true but the coroutine is fully
 * functional again — the next `sleep(2)` really sleeps two seconds. A single cancel is
 * therefore defeated by one `catch (Throwable)`.
 *
 * So an overdue request is cancelled on **every** sweep while it lives. A handler that
 * swallows the interruption cannot complete any further I/O, and reaches its end quickly
 * with nothing to show for it — which is why {@see hasExpired()} exists: the framework
 * answers 504 rather than sending a report built from queries that never ran.
 */
final class RequestWatchdog
{
    /** Sweep at most this often, however short the deadline. */
    private const float MIN_INTERVAL = 0.05;

    /**
     * …and at least this often, however long it is.
     *
     * The interval is the accuracy: a request that becomes overdue just after a sweep
     * waits for the next one, so the deadline overshoots by up to this much and by half
     * of it on average. At one second a three-second timeout answered in 3.37 s.
     *
     * 100 ms costs nothing worth counting. Measured: a sweep over 1 000 in-flight
     * requests takes 7.4 µs, so ten a second is 74 µs — 0.007 % of a core; at 5 000 it
     * is 0.037 %. An idle worker pays nothing at all, the sweep returning immediately on
     * an empty registry.
     */
    private const float MAX_INTERVAL = 0.1;

    /** Deadline (monotonic seconds) per in-flight request coroutine: cid => deadline. */
    private static array $deadlines = [];

    /** Requests already cancelled, whose result must not be sent: cid => true. */
    private static array $expired = [];

    /** Swoole timer id of this worker's sweep, or null when not armed. */
    private static ?int $timerId = null;

    /** Default deadline in seconds for requests that do not carry their own; 0 = off. */
    private static float $default = 0.0;

    private function __construct()
    {
    }

    /**
     * Arms the sweep for this worker. Call once from `workerStart`; a no-op when the
     * timeout is disabled, so an application that wants none runs no timer at all.
     */
    public static function enable(float $seconds): void
    {
        self::$default = max(0.0, $seconds);

        if (self::$timerId !== null || self::$default <= 0.0 || !extension_loaded('swoole')) {
            return;
        }

        $interval = self::sweepInterval(self::$default);
        self::$timerId = Timer::tick((int) ($interval * 1000), static fn() => self::sweep());
    }

    /** Disarms the sweep and forgets everything — for worker shutdown and for tests. */
    public static function disable(): void
    {
        if (self::$timerId !== null && extension_loaded('swoole')) {
            Timer::clear(self::$timerId);
        }
        self::$timerId   = null;
        self::$default   = 0.0;
        self::$deadlines = [];
        self::$expired   = [];
    }

    /**
     * Registers the current request and returns its coroutine id, or null when there is
     * no deadline to enforce (timeout disabled, or not running in a coroutine).
     *
     * The caller must {@see release()} the id when the request ends — via `defer`, so it
     * happens however the request finishes.
     */
    public static function register(?float $seconds = null, float $elapsed = 0.0): ?int
    {
        $seconds ??= self::$default;
        if ($seconds <= 0.0 || !Runtime::isSwooleCoroutine()) {
            return null;
        }

        $cid = Coroutine::getCid();
        // `$elapsed` is spent budget, not a start time: the deadline runs on the monotonic
        // clock, and the caller measures the wait on the wall clock Swoole stamps arrivals
        // with. Subtracting keeps the two apart. A request that used its whole budget
        // queueing gets a deadline in the past and is cancelled by the next sweep.
        self::$deadlines[$cid] = self::now() + $seconds - max(0.0, $elapsed);

        return $cid;
    }

    /**
     * Replaces the deadline of a registered request — for a route whose own
     * {@see \Flytachi\Winter\Kernel\Route\Annotation\Timeout} differs from the global
     * one, which is only known after the route has been matched.
     */
    public static function extend(?int $cid, float $seconds): void
    {
        if ($cid === null) {
            return;
        }
        if ($seconds <= 0.0) {
            unset(self::$deadlines[$cid]);   // the route opts out of the deadline
            return;
        }

        self::$deadlines[$cid] = self::now() + $seconds;
    }

    /** Whether this request was cancelled by the watchdog, so its result must be dropped. */
    public static function hasExpired(?int $cid): bool
    {
        return $cid !== null && isset(self::$expired[$cid]);
    }

    /**
     * The same question about the request running right now.
     *
     * Error handling deep in the pipeline has no reference to the id — it only has the
     * exception — so it asks about the coroutine it is already in.
     */
    public static function isCurrentExpired(): bool
    {
        if (self::$expired === [] || !Runtime::isSwooleCoroutine()) {
            return false;
        }

        return isset(self::$expired[Coroutine::getCid()]);
    }

    /** Forgets a finished request. Safe to call for an id that was never registered. */
    public static function release(?int $cid): void
    {
        if ($cid === null) {
            return;
        }
        unset(self::$deadlines[$cid], self::$expired[$cid]);
    }

    /** In-flight requests currently being watched — for diagnostics. */
    public static function watching(): int
    {
        return count(self::$deadlines);
    }

    /**
     * One pass over the registry: cancel everything past its deadline.
     *
     * Cancels again on every pass, not only the first, because cancellation is not
     * sticky — see the class docblock.
     */
    private static function sweep(): void
    {
        if (self::$deadlines === []) {
            return;
        }
        $now = self::now();

        foreach (self::$deadlines as $cid => $deadline) {
            if ($now < $deadline) {
                continue;
            }
            if (!Coroutine::exists($cid)) {
                // Finished between its deadline and this pass. Its own defer normally
                // clears the entry; dropping it here as well costs nothing and keeps the
                // registry from growing for the worker's whole life if a defer is ever
                // missed. Unsetting during foreach is safe — the loop walks a copy.
                self::release($cid);
                continue;
            }

            self::$expired[$cid] = true;
            Coroutine::cancel($cid, true);
        }
    }

    /**
     * How often to sweep for a given deadline — the accuracy of the deadline itself.
     *
     * A quarter of the deadline, clamped: never rarer than {@see MAX_INTERVAL}, so a
     * long timeout is still measured to 100 ms, and never more often than
     * {@see MIN_INTERVAL}, so a sub-second one does not spin the reactor.
     */
    private static function sweepInterval(float $seconds): float
    {
        return min(self::MAX_INTERVAL, max(self::MIN_INTERVAL, $seconds / 4));
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
