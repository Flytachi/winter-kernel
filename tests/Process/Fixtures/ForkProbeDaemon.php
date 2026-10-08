<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Integration fixture: a supervisor that queries the database between forks — in
 * `onWorkerStart()` — while its one worker queries and exits, to be restarted again and
 * again. Every fork after the first is made by a supervisor holding a connection.
 */
class ForkProbeDaemon extends Daemon
{
    protected int $replicas = 1;
    protected float $grace = 2.0;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ALWAYS, backoff: 0.1);
    }

    protected function onWorkerStart(int $slot, int $pid): void
    {
        ForkProbe::mark('supervisor ' . ForkProbe::session());
    }

    protected function workerRun(): void
    {
        ForkProbe::mark('worker ' . ForkProbe::session());
    }
}
