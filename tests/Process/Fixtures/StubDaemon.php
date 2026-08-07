<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Daemon whose desiredReplicas() is settable, for driving Supervisor damping
 * logic deterministically.
 */
final class StubDaemon extends Daemon
{
    public int $desired = 1;

    protected function desiredReplicas(): int
    {
        return $this->desired;
    }

    protected function workerRun(): void
    {
    }
}
