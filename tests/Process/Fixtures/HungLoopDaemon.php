<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;
use Flytachi\Winter\K2\Process\Daemon\RestartMode;
use Flytachi\Winter\K2\Process\Daemon\RestartPolicy;

/**
 * Integration fixture: worker wedges in a tight loop after one beat — alive by
 * PID, no heartbeat. Exercises the liveness watchdog (kill + restart) and the
 * maxRestarts ceiling.
 */
class HungLoopDaemon extends Daemon
{
    protected int $replicas = 1;
    protected float $livenessTimeout = 2.0;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, maxRestarts: 2, backoff: 0.1);
    }

    protected function workerRun(): void
    {
        $this->sleep(0.4);
        while (true) {
            // wedged — blocks the reactor, no heartbeat lands
        }
    }
}
