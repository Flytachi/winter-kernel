<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/** Does its work and returns: a job that is done, not a crash. */
class FinishingProcess extends Process
{
    public function run(): void
    {
        Marks::add('finishing', 'run');
    }
}
