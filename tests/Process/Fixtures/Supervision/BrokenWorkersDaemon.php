<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/** Its workers break while they start, before their first heartbeat: the fleet never comes up. */
class BrokenWorkersDaemon extends Daemon
{
    protected int $replicas = 1;
    protected ?string $workerClass = BrokenStartProcess::class;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, backoff: 0.1);
    }
}
