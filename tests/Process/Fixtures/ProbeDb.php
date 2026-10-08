<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Ppa\Pool\PpaPoolConfigInterface;
use Flytachi\Winter\Ppa\Pool\PpaPoolTrait;

/**
 * Integration fixture: an SQLite config (path from WK_DB) that sizes its pool by where it
 * is set up — the use `Process::current()` exists for — and records what it saw to the
 * WK_MARKER file.
 */
final class ProbeDb extends SqliteDbConfig implements PpaPoolConfigInterface
{
    use PpaPoolTrait;

    public int $poolMaxConnections = 10;

    public function setUp(): void
    {
        $this->path = (string) getenv('WK_DB');

        $running = Process::current();
        $this->poolMaxConnections = $running === null ? 10 : 3;

        Probe::mark(sprintf(
            'setUp current=%s slot=%s supervising=%s',
            $running === null ? 'null' : new \ReflectionClass($running->class)->getShortName(),
            $running?->slot ?? 'null',
            Daemon::supervising() === null ? 'null' : new \ReflectionClass(Daemon::supervising())->getShortName(),
        ));
    }
}
