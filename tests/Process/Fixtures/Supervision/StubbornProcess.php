<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Keeps working after a stop request, past its grace: the engine ends it with
 * exit(1), and no `finally` runs on the way out.
 */
class StubbornProcess extends Process
{
    protected float $grace = 1.0;

    public function run(): void
    {
        Marks::add('stubborn', 'run');
        while (true) {
            try {
                usleep(100_000);
            } catch (\Throwable) {
                // Interrupted by the stop: keeps going regardless.
            }
        }
    }
}
