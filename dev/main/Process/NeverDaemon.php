<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;

/**
 * NEVER restart: a crashed worker must NOT be replaced. Verifies the slot is
 * retired terminally (no reconcile refill / crash-loop storm).
 */
class NeverDaemon extends Daemon
{
    protected int $replicas = 2;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::NEVER);
    }

    protected function workerRun(): void
    {
        $this->logger->info('NeverDaemon worker START pid=' . $this->pid);
        $this->sleep(0.5);
        throw new \RuntimeException('boom (never restarts)');
    }
}
