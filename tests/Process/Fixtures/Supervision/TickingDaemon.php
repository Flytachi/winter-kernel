<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/** One worker that marks the time every 0.3 s — its pace shows whether its timers run. */
class TickingDaemon extends Daemon
{
    protected int $replicas = 1;

    protected function workerRun(): void
    {
        while ($this->isRunning()) {
            usleep(100_000);
            Marks::add('tick', sprintf('%.3f', microtime(true)));
            $this->sleep(0.2);
        }
    }
}
