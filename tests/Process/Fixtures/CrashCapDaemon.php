<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;
use Flytachi\Winter\K2\Process\Daemon\RestartMode;
use Flytachi\Winter\K2\Process\Daemon\RestartPolicy;

/**
 * Integration fixture: worker always crashes. Exercises restart-into-the-same-slot
 * with back-off and the maxRestarts ceiling → FAILED (self-terminates).
 */
class CrashCapDaemon extends Daemon
{
    protected int $replicas = 1;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, maxRestarts: 2, backoff: 0.1);
    }

    protected function workerRun(): void
    {
        usleep(80_000);
        throw new \RuntimeException('boom');
    }
}
