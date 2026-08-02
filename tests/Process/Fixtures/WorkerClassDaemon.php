<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Integration fixture: worker-typed daemon (no workerRun) that supervises an
 * external {@see LoopWorker} class via $workerClass.
 */
class WorkerClassDaemon extends Daemon
{
    protected int $replicas = 2;
    protected float $grace = 3.0;
    protected ?string $workerClass = LoopWorker::class;
}
