<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Worker-typed daemon: no workerRun(), supervises an external Process class.
 */
final class ExternalDaemon extends Daemon
{
    protected int $replicas = 2;
    protected ?string $workerClass = SampleProcess::class;
}
