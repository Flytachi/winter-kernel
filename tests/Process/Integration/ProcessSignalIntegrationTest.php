<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Tests\Process\Fixtures\BusyThenSleepProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ProcessSignalIntegrationTest extends IntegrationCase
{
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = $this->storage . '/marker';
        putenv('WK_MARKER=' . $this->marker);
    }

    protected function tearDown(): void
    {
        putenv('WK_MARKER');
        parent::tearDown();
    }

    /** @return list<string> */
    private function markers(): array
    {
        if (!is_file($this->marker)) {
            return [];
        }
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->marker))));
    }

    private function hasMarker(string $event): bool
    {
        return in_array($event, $this->markers(), true);
    }

    private function startAndWaitReady(): int
    {
        $pid = $this->fork(static fn() => SignalProcess::start());
        self::assertTrue(
            $this->pollUntil(fn() => $this->hasMarker('start') && SignalProcess::status() !== null),
            'the process should reach its run loop'
        );
        return $pid;
    }

    /**
     * A stop during a unit lets the unit finish — and then the wait after it was slept
     * in full: the work was done, and the process sat out its sleep(60), or its grace.
     */
    public function test_a_stop_during_work_does_not_sit_out_the_sleep_after_it(): void
    {
        $pid = $this->fork(static fn() => BusyThenSleepProcess::start());
        self::assertTrue($this->pollUntil(fn() => $this->hasMarker('unit start')));
        usleep(300_000);

        $sent = microtime(true);
        posix_kill(BusyThenSleepProcess::status()->pid, SIGTERM);

        self::assertTrue($this->waitExit($pid, 6.0), 'it slept out the sleep after the unit');
        self::assertLessThan(4.0, microtime(true) - $sent);
        self::assertSame(['unit start', 'unit done', 'shutdown'], $this->markers(), 'the unit finished, the exit was clean');
    }

    /** The body's wait is interrupted, a spawned task's waits are not: it is drained. */
    public function test_a_spawned_task_still_finishes_its_own_waits(): void
    {
        putenv('WK_SPAWN=1');
        try {
            $pid = $this->fork(static fn() => BusyThenSleepProcess::start());
            self::assertTrue($this->pollUntil(fn() => $this->hasMarker('unit start') && $this->hasMarker('task start')));
            posix_kill(BusyThenSleepProcess::status()->pid, SIGTERM);

            self::assertTrue($this->waitExit($pid, 6.0));
            self::assertContains('task done', $this->markers(), 'the task was cut short');
            self::assertContains('unit done', $this->markers());
        } finally {
            putenv('WK_SPAWN');
        }
    }

    public function test_sigterm_stops_and_fires_on_terminate(): void
    {
        $pid = $this->startAndWaitReady();

        posix_kill($pid, SIGTERM);

        self::assertTrue($this->waitExit($pid), 'SIGTERM stops the process');
        self::assertTrue($this->hasMarker('terminate'), 'onTerminate() fired');
        self::assertNull(SignalProcess::status(), 'the record is removed on exit');
    }

    public function test_sigint_stops_and_fires_on_interrupt(): void
    {
        $pid = $this->startAndWaitReady();

        posix_kill($pid, SIGINT);

        self::assertTrue($this->waitExit($pid), 'SIGINT stops the process');
        self::assertTrue($this->hasMarker('interrupt'), 'onInterrupt() fired');
    }

    public function test_sighup_fires_reload_without_stopping(): void
    {
        $pid = $this->startAndWaitReady();

        posix_kill($pid, SIGHUP);

        self::assertTrue($this->pollUntil(fn() => $this->hasMarker('reload')), 'onReload() fired');
        self::assertTrue($this->isAlive($pid), 'SIGHUP is reload, not stop');

        posix_kill($pid, SIGTERM);
        self::assertTrue($this->waitExit($pid));
    }

    public function test_sigusr1_and_sigusr2_fire_without_stopping(): void
    {
        $pid = $this->startAndWaitReady();

        posix_kill($pid, SIGUSR1);
        self::assertTrue($this->pollUntil(fn() => $this->hasMarker('user1')), 'onUser1() fired');

        posix_kill($pid, SIGUSR2);
        self::assertTrue($this->pollUntil(fn() => $this->hasMarker('user2')), 'onUser2() fired');

        self::assertTrue($this->isAlive($pid), 'user signals do not stop the process');

        posix_kill($pid, SIGTERM);
        self::assertTrue($this->waitExit($pid));
    }

    public function test_singleton_refuses_a_second_bare_process(): void
    {
        $a = $this->startAndWaitReady();

        $b = $this->fork(static fn() => SignalProcess::start());

        self::assertTrue($this->waitExit($b, 4.0), 'the second instance bails out');
        self::assertTrue($this->isAlive($a), 'the first instance is unaffected');
        $st = SignalProcess::status();
        self::assertNotNull($st);
        self::assertSame($a, $st->pid);
    }
}
