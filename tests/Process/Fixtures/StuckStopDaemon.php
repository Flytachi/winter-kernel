<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;

/**
 * Integration fixture: worker wedges and never drains, with a long grace. A first
 * stop signal begins a (stalled) drain; a second must force the fleet down at
 * once, well before the grace deadline.
 */
class StuckStopDaemon extends Daemon
{
    protected int $replicas = 1;
    protected float $grace = 8.0;

    protected function workerRun(): void
    {
        while (true) {
            // never yields, never checks isRunning() — only a SIGKILL stops it
        }
    }
}
