<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Process;

/**
 * Integration fixture: a standalone worker Process that loops until stopped —
 * used as a Daemon's $workerClass to exercise the external-worker path (and the
 * cross-instance, protected runWorker() call).
 */
class LoopWorker extends Process
{
    public function run(): void
    {
        while ($this->isRunning()) {
            $this->sleep(0.2);
        }
    }
}
