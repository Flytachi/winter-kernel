<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;

/**
 * Worker wedges in a tight loop after one healthy beat — alive by PID but making
 * no progress. Exercises the liveness watchdog: no heartbeat past
 * $livenessTimeout → SIGKILL → restart (bounded by maxRestarts → FAILED).
 */
class HungDaemon extends Daemon
{
    protected int $replicas = 1;
    protected float $livenessTimeout = 3.0;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, maxRestarts: 2, backoff: 0.2);
    }

    protected function workerRun(): void
    {
        $this->logger->info('HungDaemon worker START pid=' . $this->pid);
        $this->sleep(1.0);
        $this->logger->warning('HungDaemon worker WEDGING now pid=' . $this->pid);
        while (true) {
            // Deadlock simulation: blocks the reactor, so no heartbeat lands.
        }
    }
}
