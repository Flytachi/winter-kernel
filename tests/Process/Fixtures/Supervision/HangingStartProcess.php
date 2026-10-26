<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/** Never becomes ready: its dependency hangs while built. */
class HangingStartProcess extends Process
{
    #[Autowired]
    private SlowToBuild $slow;

    public function run(): void
    {
        Marks::add('hanging', 'run');
    }
}
