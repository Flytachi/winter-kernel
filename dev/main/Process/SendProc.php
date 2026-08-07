<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * A standalone worker Process, reusable on its own or supervised under a Daemon
 * via $workerClass. Demonstrates the afterFork() seam and BUSY/IDLE reporting.
 */
class SendProc extends Process
{
    protected function afterFork(): void
    {
        parent::afterFork(); // runs ForkReset handlers (e.g. DB pool reconnect)
        LoggerFactory::getLogger(static::class)->info('SendProc afterFork pid=' . getmypid());
    }

    public function run(): void
    {
        $this->logger->info('SendProc worker run pid=' . $this->pid);
        while ($this->isRunning()) {
            $this->markBusy();
            $this->sleep(0.4);
            $this->markIdle();
            $this->sleep(0.4);
        }
        $this->logger->info('SendProc worker exit pid=' . $this->pid);
    }
}
