<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;

/**
 * Worker crashes after a couple of ticks. Exercises ON_FAILURE restart with
 * exponential back-off (slot reused, worker#{n} stable) and the maxRestarts
 * ceiling → FAILED.
 */
class CrashDaemon extends Daemon
{
    protected int $replicas = 1;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, maxRestarts: 3, backoff: 0.5);
    }

    protected function workerRun(): void
    {
        $this->logger->info('CrashDaemon worker START pid=' . $this->pid);
        $this->sleep(0.6);
        $this->logger->warning('CrashDaemon worker about to crash pid=' . $this->pid);
        throw new \RuntimeException('boom');
    }
}
