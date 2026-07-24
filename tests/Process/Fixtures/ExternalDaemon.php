<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;

/**
 * Worker-typed daemon: no workerRun(), supervises an external Process class.
 */
final class ExternalDaemon extends Daemon
{
    protected int $replicas = 2;
    protected ?string $workerClass = SampleProcess::class;
}
