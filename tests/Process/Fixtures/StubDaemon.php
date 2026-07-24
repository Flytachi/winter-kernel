<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;

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
