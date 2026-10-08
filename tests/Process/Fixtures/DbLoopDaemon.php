<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

/**
 * Integration fixture: a fleet whose workers query the database on every pass — which,
 * under Swoole, arms a connection pool's housekeeping timer in each worker.
 */
class DbLoopDaemon extends Daemon
{
    protected int $replicas = 2;
    protected float $grace = 5.0;

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ON_FAILURE, backoff: 0.1);
    }

    protected function workerRun(): void
    {
        while ($this->isRunning()) {
            PpaConnectionPool::db(PoolExitDb::class)->query('SELECT 1');
            $this->sleep(0.1);
        }
    }
}
