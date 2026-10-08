<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\DbLoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\DbLoopProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * A process or daemon whose body used the database must still stop on SIGTERM.
 *
 * Under Swoole the body runs inside `Coroutine\run()`, and the connection pool arms a
 * repeating housekeeping timer on its first borrow. Until the engine released it, the
 * body finished its loop on a stop request and the process then hung — its grace timer
 * already disarmed — until something outside killed it.
 */
#[Group('integration')]
final class PoolExitIntegrationTest extends IntegrationCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('the hang is a property of the Swoole scheduler.');
        }
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('the pool is exercised against SQLite.');
        }
        parent::setUp();
        putenv('WK_DB=' . $this->storage . '/pool-exit.sqlite');
    }

    protected function tearDown(): void
    {
        putenv('WK_DB');
        parent::tearDown();
    }

    private function daemonStatus(string $class): ?DaemonStatus
    {
        $s = $class::status();
        return $s instanceof DaemonStatus ? $s : null;
    }

    public function test_a_process_that_used_the_database_stops_on_sigterm(): void
    {
        $pid = $this->fork(static fn() => DbLoopProcess::start());
        self::assertTrue($this->pollUntil(static fn() => DbLoopProcess::status() !== null));
        usleep(300_000);    // a few passes: the pool is open and its timer armed

        posix_kill($pid, SIGTERM);

        self::assertTrue($this->waitExit($pid, 6.0), 'the process exits instead of hanging on the pool timer');
        self::assertNull(DbLoopProcess::status(), 'the record is removed on exit');
    }

    public function test_a_daemon_whose_workers_used_the_database_stops_on_sigterm(): void
    {
        $sup = $this->fork(static fn() => DbLoopDaemon::start());
        self::assertTrue($this->pollUntil(
            fn() => ($st = $this->daemonStatus(DbLoopDaemon::class)) !== null && count($st->workers) === 2
        ));
        $workerPids = array_map(static fn($w) => $w->pid, $this->daemonStatus(DbLoopDaemon::class)->workers);
        usleep(300_000);

        $stoppedAt = microtime(true);
        posix_kill($sup, SIGTERM);

        // The fleet's grace is 5 s: past it the supervisor SIGKILLs whatever is left, so
        // a hung worker still "stops" — only late and by force. Ending well inside the
        // grace is what says the workers left on their own.
        self::assertTrue($this->waitExit($sup, 8.0), 'the supervisor exits');
        $took = microtime(true) - $stoppedAt;
        self::assertLessThan(3.0, $took, 'workers end on their own instead of waiting out the grace to be killed');
        foreach ($workerPids as $pid) {
            self::assertFalse($this->isAlive($pid), "worker {$pid} ended on its own, not orphaned or hung");
        }
    }
}
