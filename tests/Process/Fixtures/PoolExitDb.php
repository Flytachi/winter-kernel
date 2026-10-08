<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;

/** Integration fixture: an SQLite database at the path named by the WK_DB env var. */
final class PoolExitDb extends SqliteDbConfig
{
    public function setUp(): void
    {
        $this->path = (string) getenv('WK_DB');
    }
}
