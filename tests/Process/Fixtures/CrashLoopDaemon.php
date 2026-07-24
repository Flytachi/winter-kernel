<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;
use Flytachi\Winter\K2\Process\Daemon\RestartMode;
use Flytachi\Winter\K2\Process\Daemon\RestartPolicy;

/**
 * Integration fixture: worker crashes on a loop, restarted forever (no ceiling),
 * so restart-into-the-same-slot and the restart counter can be observed steadily.
 */
class CrashLoopDaemon extends Daemon
{
    protected int $replicas = 1;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, maxRestarts: 0, backoff: 0.1);
    }

    protected function workerRun(): void
    {
        usleep(120_000);
        throw new \RuntimeException('boom');
    }
}
