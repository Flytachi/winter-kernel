<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/** Breaks while it starts as long as its fault is there; runs once it is fixed. */
class FixableProcess extends Process
{
    #[Autowired]
    private FlaggedExplosive $dependency;

    public function run(): void
    {
        Marks::add('fixable', 'run');
        while ($this->isRunning()) {
            $this->sleep(0.1);
        }
    }
}
