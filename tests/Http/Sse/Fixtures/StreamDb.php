<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Sse\Fixtures;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;

/** An SQLite database at the path in WK_SSE_DB, for the pool a request borrows from. */
final class StreamDb extends SqliteDbConfig
{
    public function setUp(): void
    {
        $this->path = (string) getenv('WK_SSE_DB');
    }
}
