<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

/**
 * Integration fixture: a bare process whose body queries the database on every pass —
 * which, under Swoole, arms the connection pool's housekeeping timer.
 */
class DbLoopProcess extends Process
{
    protected float $grace = 2.0;

    public function run(): void
    {
        while ($this->isRunning()) {
            PpaConnectionPool::db(PoolExitDb::class)->query('SELECT 1');
            $this->sleep(0.1);
        }
    }
}
