<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

/**
 * Integration fixture: stands in for an `#[Autowired]` repository. Its constructor does
 * what `RepositoryCore`'s does — registers the DB config, running its `setUp()` — so the
 * config is set up while the process is still being built.
 */
final class ProbeRepository
{
    public function __construct()
    {
        Probe::mark(sprintf(
            'built current=%s supervising=%s',
            Process::current() === null ? 'null' : new \ReflectionClass(Process::current()->class)->getShortName(),
            Daemon::supervising() === null ? 'null' : new \ReflectionClass(Daemon::supervising())->getShortName(),
        ));
        PpaConnectionPool::getConfigDb(ProbeDb::class);
    }
}
