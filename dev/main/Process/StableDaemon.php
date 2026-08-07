<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Long-lived worker that loops until stopped. Exercises the graceful stop of a
 * supervised daemon: SIGTERM to the supervisor → whole fleet drained → clean
 * exit, no restart. Inline body via workerRun().
 */
class StableDaemon extends Daemon
{
    protected int $replicas = 2;

    protected function workerRun(): void
    {
        $this->logger->info('StableDaemon worker START pid=' . $this->pid);
        $tick = 0;
        while ($this->isRunning()) {
            $this->logger->info('StableDaemon worker ' . $this->pid . ' tick ' . (++$tick));
            $this->sleep(1.0);
        }
        $this->logger->info('StableDaemon worker ' . $this->pid . ' graceful exit');
    }
}
