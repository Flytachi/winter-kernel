<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;
use Flytachi\Winter\Kernel\Process\Daemon\ScalingPolicy;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

/**
 * Integration fixture: a supervisor whose `onWorkerStart()` leaves a transaction open
 * after the second start and commits it in `tick()` a second and a half later. Its one
 * worker queries and exits, to be restarted at once — so a restart is due while the
 * supervisor's transaction is open.
 */
class ForkTxnDaemon extends Daemon
{
    protected int $replicas = 1;
    protected float $grace = 2.0;

    private int $starts = 0;
    private ?float $openedAt = null;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ALWAYS, backoff: 0.05);
    }

    protected function scaling(): ScalingPolicy
    {
        return new ScalingPolicy(scaleInterval: 0.1);
    }

    protected function onWorkerStart(int $slot, int $pid): void
    {
        $this->starts++;
        ForkProbe::mark('supervisor ' . ForkProbe::session());
        if ($this->starts === 2) {
            PpaConnectionPool::db(ForkProbe::config())->beginTransaction();
            $this->openedAt = microtime(true);
            ForkProbe::mark('transaction opened');
        }
    }

    protected function tick(): void
    {
        if ($this->openedAt !== null && microtime(true) - $this->openedAt > 1.5) {
            PpaConnectionPool::db(ForkProbe::config())->commit();
            $this->openedAt = null;
            ForkProbe::mark('transaction committed');
        }
    }

    protected function workerRun(): void
    {
        ForkProbe::mark('worker ' . ForkProbe::session());
    }
}
