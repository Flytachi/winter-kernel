<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Integration fixture: worker alternates BUSY / IDLE, so the supervisor's
 * per-worker activity heartbeat can be observed in the fleet status.
 */
class BusyIdleDaemon extends Daemon
{
    protected int $replicas = 1;

    protected function workerRun(): void
    {
        while ($this->isRunning()) {
            $this->markBusy();
            $this->sleep(0.15);
            $this->markIdle();
            $this->sleep(0.15);
        }
    }
}
