<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Integration fixture: a one-worker daemon whose `#[Autowired]` repository is built — and
 * its DB config set up — in the supervisor; the forked worker then reports the pool
 * ceiling it got.
 */
class ProbeDaemon extends Daemon
{
    protected int $replicas = 1;
    protected float $grace = 2.0;

    #[Autowired]
    private ProbeRepository $repository;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, backoff: 0.1);
    }

    protected function workerRun(): void
    {
        Probe::markPoolMaximum('worker');
        while ($this->isRunning()) {
            $this->sleep(0.1);
        }
    }
}
