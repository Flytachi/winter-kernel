<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures;

use Flytachi\Winter\K2\Tests\Ppa\Fixtures\StubDbConfig;

/**
 * Shared no-op DbConfig used by every Repository test. PpaConnectionPool
 * caches instances by class name, so the same config is reused across tests.
 */
final class RepoTestDbConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}
