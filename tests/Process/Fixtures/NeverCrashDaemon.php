<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;

/**
 * Integration fixture: NEVER restart + a crashing worker. Each dead worker must
 * be retired terminally (no restart storm); the daemon keeps running.
 */
class NeverCrashDaemon extends Daemon
{
    protected int $replicas = 2;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::NEVER);
    }

    protected function workerRun(): void
    {
        usleep(80_000);
        throw new \RuntimeException('boom');
    }
}
