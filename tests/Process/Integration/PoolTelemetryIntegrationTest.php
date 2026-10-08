<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\DbLoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\DbLoopProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\PoolExitDb;
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
use PHPUnit\Framework\Attributes\Group;

/**
 * A process keeps a connection pool of its own, apart from the web workers, so it
 * publishes its utilisation as a source of its own: `call db pool` shows it in its
 * own group instead of not at all.
 */
#[Group('integration')]
final class PoolTelemetryIntegrationTest extends IntegrationCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('pool telemetry is published from a Swoole timer.');
        }
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('the pool is exercised against SQLite.');
        }
        putenv('PPA_POOL_TELEMETRY=1');
        parent::setUp();
        // After parent::setUp(): tearDown() still runs for a skipped test and needs it.
        if (!defined(PoolTelemetry::class . '::KIND_PROCESS')) {
            self::markTestSkipped('per-process telemetry needs winter-ppa 1.2 or later.');
        }
        putenv('WK_DB=' . $this->storage . '/pool-telemetry.sqlite');
    }

    protected function tearDown(): void
    {
        putenv('WK_DB');
        putenv('PPA_POOL_TELEMETRY');
        parent::tearDown();
    }

    /** @return list<string> `kind:name` of every record currently published. */
    private static function sources(): array
    {
        return array_map(
            static fn(array $r): string => $r['kind'] . ':' . $r['worker'],
            PoolTelemetry::snapshot(),
        );
    }

    public function test_a_running_process_publishes_its_pool_and_withdraws_it_on_stop(): void
    {
        $pid = $this->fork(static fn() => DbLoopProcess::start());

        self::assertTrue(
            $this->pollUntil(static fn() => in_array('process:' . DbLoopProcess::class, self::sources(), true)),
            'the process should appear as a source of kind "process"',
        );
        $record = PoolTelemetry::snapshot()[0];
        self::assertSame($pid, $record['pid']);
        self::assertArrayHasKey(PoolExitDb::class, $record['pools']);
        self::assertArrayHasKey(PoolExitDb::class, PoolTelemetry::aggregate(PoolTelemetry::KIND_PROCESS));
        self::assertSame([], PoolTelemetry::aggregate(PoolTelemetry::KIND_WEB), 'nothing reads as a web worker');

        posix_kill($pid, SIGTERM);

        self::assertTrue($this->waitExit($pid), 'the process stops');
        self::assertSame([], self::sources(), 'its record is withdrawn at once, not left to expire');
    }

    public function test_each_daemon_worker_publishes_under_its_slot(): void
    {
        $sup = $this->fork(static fn() => DbLoopDaemon::start());

        self::assertTrue(
            $this->pollUntil(static fn() => self::sources() === [
                'daemon:' . DbLoopDaemon::class . '.0',
                'daemon:' . DbLoopDaemon::class . '.1',
            ]),
            'two workers, two sources: ' . implode(', ', self::sources()),
        );

        posix_kill($sup, SIGTERM);

        self::assertTrue($this->waitExit($sup), 'the fleet stops');
        self::assertSame([], self::sources());
    }
}
