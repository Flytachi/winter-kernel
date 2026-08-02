<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Integration;

use Flytachi\Winter\Kernel\Tests\Process\Integration\IntegrationCase;
use Flytachi\Winter\Kernel\Tests\Schedule\Fixtures\MarkerScheduler;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live scheduler run: forks a real {@see MarkerScheduler}, which fires its task
 * through the actual engine ({@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::spawn()}),
 * and observes the firing through the WK_MARKER file — then a real SIGTERM stops
 * it. Exercises the true boot, coroutine/fork, and graceful-stop machinery.
 */
#[Group('integration')]
final class SchedulerIntegrationTest extends IntegrationCase
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

    private function tickCount(): int
    {
        if (!is_file($this->marker)) {
            return 0;
        }
        return count(array_filter(explode("\n", (string) file_get_contents($this->marker))));
    }

    public function test_fires_repeatedly_then_stops_gracefully(): void
    {
        $pid = $this->fork(static fn() => MarkerScheduler::start());

        // The task (fixedRate 0.2s) should fire several times, proving the loop
        // dispatches through the engine and the bean is resolved and invoked.
        self::assertTrue(
            $this->pollUntil(fn() => $this->tickCount() >= 3 && MarkerScheduler::status() !== null),
            'the scheduler should fire its task repeatedly'
        );

        posix_kill($pid, SIGTERM);

        self::assertTrue($this->waitExit($pid), 'SIGTERM stops the scheduler');
        self::assertNull(MarkerScheduler::status(), 'the record is removed on exit');
    }
}
