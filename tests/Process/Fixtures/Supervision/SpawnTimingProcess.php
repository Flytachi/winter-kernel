<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/** Three 0.3 s waits through spawn(): concurrent only when the runtime hooks are on. */
class SpawnTimingProcess extends Process
{
    public function run(): void
    {
        $started = microtime(true);
        $futures = [];
        for ($i = 0; $i < 3; $i++) {
            $futures[] = $this->spawn(static fn() => usleep(300_000));
        }
        foreach ($futures as $future) {
            $future->get();
        }
        Marks::add('spawn', sprintf('%.3f', microtime(true) - $started));
    }
}
