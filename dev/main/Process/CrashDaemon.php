<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Dev\Process\Daemon;
use Flytachi\Winter\K2\Dev\Process\RestartPolicy;

/**
 * Worker crashes after a couple of ticks. Exercises ON_FAILURE restart with
 * exponential back-off and the maxRestarts ceiling → FAILED.
 */
class CrashDaemon extends Daemon
{
    protected int $replicas = 1;
    protected RestartPolicy $restart = RestartPolicy::ON_FAILURE;
    protected int $maxRestarts = 3;
    protected float $backoff = 0.5;

    public function run(): void
    {
        $this->logger->info('CrashDaemon worker START pid=' . $this->pid);
        $this->sleep(0.6);
        $this->logger->warning('CrashDaemon worker about to crash pid=' . $this->pid);
        throw new \RuntimeException('boom');
    }
}
