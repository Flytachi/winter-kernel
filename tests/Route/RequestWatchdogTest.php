<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Route\RequestWatchdog;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * The request deadline, which Swoole does not provide.
 *
 * Verified against the extension itself before writing any of this: no option in any of
 * its lists concerns execution time, `max_request_execution_time` appears nowhere in the
 * binary, and setting it changes nothing — a handler sleeping three seconds under a
 * one-second "limit" still answers 200 after three.
 *
 * Two measured facts shape the design, and both are asserted below:
 *
 *  - **Cancellation is not sticky.** After application code catches the
 *    `CanceledException`, the coroutine is fully functional again — the next `sleep(2)`
 *    really sleeps two seconds. One cancel is defeated by one `catch (Throwable)`, so
 *    an overdue request is cancelled on every sweep.
 *  - **A request burning CPU cannot be interrupted**, because the event loop is
 *    single-threaded: while a handler loops without yielding, the sweep itself cannot
 *    run. Nothing here can change that; the tests state it rather than hide it.
 */
final class RequestWatchdogTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The watchdog is a Swoole timer.');
        }
        RequestWatchdog::disable();
    }

    protected function tearDown(): void
    {
        RequestWatchdog::disable();
        \Swoole\Timer::clearAll();
    }

    // ── Registration ───────────────────────────────────────────────────────────

    public function test_nothing_is_watched_while_the_deadline_is_disabled(): void
    {
        $seen = null;

        \Swoole\Coroutine\run(static function () use (&$seen): void {
            RequestWatchdog::enable(0.0);
            $seen = RequestWatchdog::register();
            RequestWatchdog::disable();
        });

        self::assertNull($seen, 'a disabled deadline registers nobody');
        self::assertSame(0, RequestWatchdog::watching());
    }

    public function test_a_request_is_watched_and_released(): void
    {
        $during = $after = null;

        \Swoole\Coroutine\run(static function () use (&$during, &$after): void {
            RequestWatchdog::enable(5.0);
            $cid = RequestWatchdog::register();
            $during = RequestWatchdog::watching();
            RequestWatchdog::release($cid);
            $after = RequestWatchdog::watching();
            RequestWatchdog::disable();
        });

        self::assertSame(1, $during);
        self::assertSame(0, $after, 'a finished request is forgotten');
    }

    public function test_releasing_something_never_registered_is_harmless(): void
    {
        RequestWatchdog::release(null);
        RequestWatchdog::release(999999);

        $this->addToAssertionCount(1);
    }

    /**
     * Releasing must also clear the expiry mark, because **Swoole reuses coroutine ids**.
     * A mark left behind on a finished request would be found by the next request handed
     * the same id, which would then be answered 504 having done nothing wrong.
     */
    public function test_releasing_clears_the_expiry_mark_so_a_reused_id_starts_clean(): void
    {
        $expiredBefore = $expiredAfterReuse = null;

        \Swoole\Coroutine\run(static function () use (&$expiredBefore, &$expiredAfterReuse): void {
            RequestWatchdog::enable(0.05);
            $cid = null;

            Coroutine::create(static function () use (&$cid, &$expiredBefore): void {
                $cid = RequestWatchdog::register();
                try {
                    Coroutine::sleep(1);
                } catch (\Throwable) {
                    // timed out, as intended
                }
                $expiredBefore = RequestWatchdog::hasExpired($cid);
                RequestWatchdog::release($cid);
            });

            Coroutine::sleep(0.4);
            // Whatever id that request had, a later one may be given it again.
            $expiredAfterReuse = RequestWatchdog::hasExpired($cid);
            RequestWatchdog::disable();
        });

        self::assertTrue($expiredBefore, 'the request really did time out');
        self::assertFalse($expiredAfterReuse, 'the mark must not outlive the request that earned it');
    }

    // ── The deadline ───────────────────────────────────────────────────────────

    /** The case the class exists for: a waiting request is interrupted, cleanly. */
    public function test_a_waiting_request_is_cancelled_at_its_deadline(): void
    {
        $outcome = null;
        $finallyRan = false;
        $deferRan = false;

        \Swoole\Coroutine\run(static function () use (&$outcome, &$finallyRan, &$deferRan): void {
            RequestWatchdog::enable(0.15);

            Coroutine::create(static function () use (&$outcome, &$finallyRan, &$deferRan): void {
                $cid = RequestWatchdog::register();
                Coroutine::defer(static function () use (&$deferRan, $cid): void {
                    $deferRan = true;               // the pool returns its connection here
                    RequestWatchdog::release($cid);
                });
                try {
                    Coroutine::sleep(5);
                    $outcome = 'finished';
                } catch (\Throwable $e) {
                    $outcome = $e::class;
                } finally {
                    $finallyRan = true;             // …and a transaction closes here
                }
            });

            Coroutine::sleep(0.4);
            RequestWatchdog::disable();
        });

        self::assertSame('Swoole\Coroutine\CanceledException', $outcome);
        self::assertTrue($finallyRan, 'finally must run — transactions have to close');
        self::assertTrue($deferRan, 'defer must run — pooled connections have to come back');
    }

    public function test_a_request_inside_its_deadline_is_untouched(): void
    {
        $outcome = null;

        \Swoole\Coroutine\run(static function () use (&$outcome): void {
            RequestWatchdog::enable(1.0);

            Coroutine::create(static function () use (&$outcome): void {
                $cid = RequestWatchdog::register();
                try {
                    Coroutine::sleep(0.05);
                    $outcome = RequestWatchdog::hasExpired($cid) ? 'expired' : 'ok';
                } catch (\Throwable $e) {
                    $outcome = $e::class;
                }
                RequestWatchdog::release($cid);
            });

            Coroutine::sleep(0.3);
            RequestWatchdog::disable();
        });

        self::assertSame('ok', $outcome);
    }

    /**
     * A handler that catches the cancellation must not get away with it: every further
     * wait is cut short, and the request stays marked so the framework answers 504
     * instead of sending a result built from queries that never ran.
     */
    public function test_swallowing_the_cancellation_does_not_defeat_the_deadline(): void
    {
        $completedWaits = 0;
        $expired = null;

        \Swoole\Coroutine\run(static function () use (&$completedWaits, &$expired): void {
            RequestWatchdog::enable(0.1);

            Coroutine::create(static function () use (&$completedWaits, &$expired): void {
                $cid = RequestWatchdog::register();
                for ($i = 0; $i < 4; $i++) {
                    try {
                        Coroutine::sleep(0.5);
                        $completedWaits++;          // never, once the deadline passed
                    } catch (\Throwable) {
                        // swallowed on purpose
                    }
                }
                $expired = RequestWatchdog::hasExpired($cid);
                RequestWatchdog::release($cid);
            });

            Coroutine::sleep(1.0);
            RequestWatchdog::disable();
        });

        self::assertSame(0, $completedWaits, 'no wait of an overdue request may complete');
        self::assertTrue($expired, 'the framework must know to answer 504');
    }

    // ── Per-route override ─────────────────────────────────────────────────────

    public function test_a_route_may_extend_its_deadline(): void
    {
        $outcome = null;

        \Swoole\Coroutine\run(static function () use (&$outcome): void {
            RequestWatchdog::enable(0.1);

            Coroutine::create(static function () use (&$outcome): void {
                $cid = RequestWatchdog::register();
                RequestWatchdog::extend($cid, 5.0);   // #[Timeout(5)] on this route
                try {
                    Coroutine::sleep(0.3);            // longer than the global deadline
                    $outcome = 'finished';
                } catch (\Throwable $e) {
                    $outcome = $e::class;
                }
                RequestWatchdog::release($cid);
            });

            Coroutine::sleep(0.6);
            RequestWatchdog::disable();
        });

        self::assertSame('finished', $outcome, 'the route\'s own deadline must win');
    }

    /** `#[Timeout(0)]` opts a route out entirely. */
    public function test_a_route_may_opt_out_of_the_deadline(): void
    {
        $outcome = null;

        \Swoole\Coroutine\run(static function () use (&$outcome): void {
            RequestWatchdog::enable(0.1);

            Coroutine::create(static function () use (&$outcome): void {
                $cid = RequestWatchdog::register();
                RequestWatchdog::extend($cid, 0.0);
                try {
                    Coroutine::sleep(0.3);
                    $outcome = 'finished';
                } catch (\Throwable $e) {
                    $outcome = $e::class;
                }
                RequestWatchdog::release($cid);
            });

            Coroutine::sleep(0.6);
            RequestWatchdog::disable();
        });

        self::assertSame('finished', $outcome);
        self::assertSame(0, RequestWatchdog::watching(), 'an opted-out route is not watched');
    }

    public function test_extending_something_never_registered_is_harmless(): void
    {
        RequestWatchdog::extend(null, 10.0);

        $this->addToAssertionCount(1);
    }

    // ── The limit, stated rather than hidden ───────────────────────────────────

    /**
     * A handler that never yields cannot be interrupted — and the sweep cannot even run
     * while it holds the loop. This is a property of a single-threaded event loop, not
     * something the watchdog could fix; PHP-FPM has the mirror-image limitation, killing
     * a runaway loop but not a hung query.
     */
    public function test_a_cpu_bound_request_is_not_interrupted(): void
    {
        $finished = false;

        \Swoole\Coroutine\run(static function () use (&$finished): void {
            RequestWatchdog::enable(0.05);

            Coroutine::create(static function () use (&$finished): void {
                $cid = RequestWatchdog::register();
                $x = 0;
                for ($i = 0; $i < 20_000_000; $i++) {   // no yield point anywhere
                    $x += $i;
                }
                $finished = true;
                RequestWatchdog::release($cid);
            });

            Coroutine::sleep(0.3);
            RequestWatchdog::disable();
        });

        self::assertTrue($finished, 'a CPU-bound handler runs to completion — documented, not a bug');
    }
}
