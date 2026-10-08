<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Integration fixture: an external daemon worker, built in the forked child through the
 * container — the config its `#[Autowired]` repository sets up there must see the worker
 * it belongs to.
 */
class ProbeWorker extends Process
{
    #[Autowired]
    private ProbeRepository $repository;

    public function run(): void
    {
        Probe::markPoolMaximum('worker');
        while ($this->isRunning()) {
            $this->sleep(0.1);
        }
    }
}
