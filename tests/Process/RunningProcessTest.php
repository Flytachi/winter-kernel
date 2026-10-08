<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\RunningProcess;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\LoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SampleProcess;
use PHPUnit\Framework\TestCase;

final class RunningProcessTest extends TestCase
{
    protected function setUp(): void
    {
        self::forget();
    }

    protected function tearDown(): void
    {
        self::forget();
    }

    /** Other tests start processes inside this runner; the statics outlive them. */
    private static function forget(): void
    {
        new \ReflectionProperty(Process::class, 'current')->setValue(null, null);
        new \ReflectionProperty(Daemon::class, 'supervising')->setValue(null, null);
    }

    public function test_outside_any_process_there_is_no_current_one(): void
    {
        // A web worker, the console, this test runner: nothing was started as a process.
        self::assertNull(Process::current());
        self::assertNull(Daemon::supervising());
    }

    public function test_a_plain_process_has_no_slot(): void
    {
        $running = new RunningProcess(SampleProcess::class);

        self::assertSame(SampleProcess::class, $running->class);
        self::assertNull($running->slot);
        self::assertFalse($running->isDaemonWorker());
    }

    public function test_a_daemon_worker_carries_its_daemon_and_slot(): void
    {
        $running = new RunningProcess(LoopDaemon::class, 2);

        self::assertSame(LoopDaemon::class, $running->class);
        self::assertSame(2, $running->slot);
        self::assertTrue($running->isDaemonWorker());
    }
}
