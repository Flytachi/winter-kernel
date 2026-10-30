<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * A unit of work, then a long wait, as a worker that polls does. The stop arrives
 * during the unit; the wait after it used to be slept in full. Marks go to WK_MARKER.
 * With `WK_SPAWN` set, the body also spawns a task that waits in steps of its own — a
 * task is drained, never interrupted.
 */
class BusyThenSleepProcess extends Process
{
    protected float $grace = 0.0;

    private function mark(string $event): void
    {
        file_put_contents((string) getenv('WK_MARKER'), $event . "\n", FILE_APPEND | LOCK_EX);
    }

    public function run(): void
    {
        if (getenv('WK_SPAWN')) {
            $this->spawn(function (): void {
                $this->mark('task start');
                for ($i = 0; $i < 10; $i++) {
                    $this->sleep(0.2);
                }
                $this->mark('task done');
            });
        }
        while ($this->isRunning()) {
            $this->markBusy();
            $this->mark('unit start');
            $end = microtime(true) + 1.5;
            while (microtime(true) < $end) {
                usleep(50_000);
            }
            $this->mark('unit done');
            $this->markIdle();
            $this->sleep(60);
        }
    }

    protected function onShutdown(): void
    {
        $this->mark('shutdown');
    }
}
