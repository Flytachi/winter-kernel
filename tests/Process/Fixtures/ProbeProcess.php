<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Integration fixture: a process with an `#[Autowired]` repository, whose construction
 * sets the DB config up while the process is still being built; the body then reports
 * the pool ceiling it got.
 */
class ProbeProcess extends Process
{
    #[Autowired]
    private ProbeRepository $repository;

    public function run(): void
    {
        Probe::markPoolMaximum('process');
        while ($this->isRunning()) {
            $this->sleep(0.1);
        }
    }
}
