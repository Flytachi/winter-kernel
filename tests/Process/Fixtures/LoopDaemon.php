<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;

/**
 * Integration fixture: a long-lived worker fleet that loops until stopped.
 * Used to observe fork, per-worker status, graceful stop and reload live.
 */
class LoopDaemon extends Daemon
{
    protected int $replicas = 2;
    protected float $grace = 3.0;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, backoff: 0.1);
    }

    protected function workerRun(): void
    {
        while ($this->isRunning()) {
            $this->sleep(0.2);
        }
    }
}
