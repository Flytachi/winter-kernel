<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/** Breaks while it starts: its dependency cannot be built. */
class BrokenStartProcess extends Process
{
    #[Autowired]
    private Explosive $explosive;

    public function run(): void
    {
        Marks::add('broken', 'run');
    }
}
