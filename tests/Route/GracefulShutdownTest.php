<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Route;

use Flytachi\Winter\K2\Tests\Route\Fixtures\ServerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The server must be able to leave when asked.
 *
 * A worker cannot exit while its reactor still holds a repeating `Swoole\Timer`, so one
 * timer nobody clears turns every shutdown into a wait followed by
 * `Worker_reactor_try_to_exit() (ERRNO 9101): worker exit timeout, forced termination`.
 * That shipped once — the pool telemetry armed a tick per worker and nothing released
 * it — and it was invisible to the whole suite, because no test had ever watched a
 * server shut down. Anything that arms a timer in a worker has to release it in
 * `workerExit`; this is what notices when it does not.
 */
#[Group('integration')]
final class GracefulShutdownTest extends TestCase
{
    /** Swoole force-kills a worker a few seconds in, so a clean stop is well under this. */
    private const float EXIT_BUDGET = 10.0;

    private ?ServerProcess $server = null;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole') || !extension_loaded('posix')) {
            self::markTestSkipped('needs Swoole and posix.');
        }

        $this->server = new ServerProcess();
        if (!$this->server->start()) {
            $log = $this->server->log();
            $this->server->stop();
            $this->server = null;
            self::markTestSkipped("the server did not come up in time:\n" . substr($log, 0, 600));
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function test_sigterm_stops_the_server_without_a_forced_kill(): void
    {
        // Serve something first: a worker that has handled a request is the one whose
        // timers and pools are actually live.
        self::assertSame(200, $this->server->request('GET', '/demo/ping')['status']);

        $this->server->signal(SIGTERM);

        self::assertTrue(
            $this->server->awaitExit(self::EXIT_BUDGET),
            "the server was still running " . self::EXIT_BUDGET . "s after SIGTERM:\n" . $this->server->log(),
        );

        $log = $this->server->log();
        foreach (['9101', 'exit timeout', 'forced termination'] as $symptom) {
            self::assertStringNotContainsStringIgnoringCase(
                $symptom,
                $log,
                "the reactor could not drain — something in the worker still holds a timer:\n" . $log,
            );
        }
    }

    public function test_it_stops_cleanly_even_without_serving_anything(): void
    {
        // The timers are armed at workerStart, so an idle worker has to release them too.
        $this->server->signal(SIGTERM);

        self::assertTrue($this->server->awaitExit(self::EXIT_BUDGET), $this->server->log());
        self::assertStringNotContainsStringIgnoringCase('9101', $this->server->log());
    }
}
