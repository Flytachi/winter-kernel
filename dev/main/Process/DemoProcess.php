<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Dev\Process\Process;

/**
 * One-shot demo: dispatch 6 tasks with a concurrency cap of 2 and exit.
 * Exercises the coroutine engine — semaphore back-pressure + drain-on-exit.
 */
class DemoProcess extends Process
{
    protected int $concurrency = 2;

    public function run(): void
    {
        $this->logger->info('DemoProcess START (concurrency=' . $this->concurrency . ')');

        for ($i = 1; $i <= 6; $i++) {
            $this->spawn(fn() => $this->task($i));
        }

        $this->logger->info('DemoProcess loop done, draining...');
    }

    private function task(int $n): void
    {
        $this->logger->info("  task #$n start (inFlight)");
        $this->sleep(0.3);
        $this->logger->info("  task #$n done");
    }
}
