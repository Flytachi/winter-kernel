<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Dev\Process\Daemon;
use Flytachi\Winter\K2\Dev\Process\RestartPolicy;

/**
 * Long-lived worker that loops until stopped. Exercises the graceful stop of a
 * supervised daemon: SIGTERM to the supervisor → workers signalled → clean exit,
 * no restart.
 */
class StableDaemon extends Daemon
{
    protected int $replicas = 2;
    protected RestartPolicy $restart = RestartPolicy::ON_FAILURE;

    public function run(): void
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
