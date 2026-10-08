<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/** Integration fixture: a one-worker daemon running an external {@see ProbeWorker}. */
class ProbeWorkerClassDaemon extends Daemon
{
    protected int $replicas = 1;
    protected float $grace = 2.0;
    protected ?string $workerClass = ProbeWorker::class;
}
