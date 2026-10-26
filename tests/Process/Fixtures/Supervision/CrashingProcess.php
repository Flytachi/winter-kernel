<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/** Starts fine, then crashes a moment later — every time. */
class CrashingProcess extends Process
{
    public function run(): void
    {
        Marks::add('crashing', 'run ' . sprintf('%.3f', microtime(true)));
        usleep(200_000);
        throw new \RuntimeException('crashed while running');
    }
}
