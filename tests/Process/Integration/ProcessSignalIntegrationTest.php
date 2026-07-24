<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Integration;

use Flytachi\Winter\K2\Tests\Process\Fixtures\SignalProcess;
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
