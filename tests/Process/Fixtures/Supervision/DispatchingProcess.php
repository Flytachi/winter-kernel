<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/** Starts another process from its own body — as a controller starting an export would. */
class DispatchingProcess extends Process
{
    public function run(): void
    {
        FinishingProcess::dispatch();
        while ($this->isRunning()) {
            $this->sleep(0.1);
        }
    }
}
