<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Process\InterruptedException;
use Flytachi\Winter\K2\Process\Process;

/**
 * Consumer-style demo: IDLE wait, then a BUSY unit. Proves drain-to-idle — a
 * stop during a BUSY unit lets it finish (not interrupted), a stop during the
 * IDLE wait wakes it at once.
 */
class ConsumerDemo extends Process
{
    protected float $grace = 0.0;   // wait for the busy unit no matter how long

    public function run(): void
    {
        $this->logger->info('Consumer started');
        $n = 0;

        try {
            while ($this->isRunning()) {
                $this->sleep(0.5);                       // IDLE wait (interruptible)

                $n++;
                $this->markBusy();
                $this->logger->info("unit #{$n}: BUSY start");
                $this->sleep(3.0);                       // long processing — must finish on stop
                $this->logger->info("unit #{$n}: BUSY done");
                $this->markIdle();
            }
        } catch (InterruptedException) {
            $this->logger->info('Woken from IDLE wait by stop');
        }

        $this->logger->info('Consumer stopped');
    }
}
