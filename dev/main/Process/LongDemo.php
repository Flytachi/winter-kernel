<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Dev\Process\Process;

/**
 * Long-lived demo: ticks until stopped. Exercises running()/sleep() and the
 * graceful stop path (SIGTERM → running() flips false → clean exit).
 */
class LongDemo extends Process
{
    public function run(): void
    {
        $this->logger->info('LongDemo START pid=' . $this->pid);

        $tick = 0;
        while ($this->running()) {
            $this->logger->info('LongDemo tick ' . (++$tick));
            $this->sleep(1.0);
        }

        $this->logger->info('LongDemo graceful exit after ' . $tick . ' ticks');
    }
}
