<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\MariadbTestDbConfig;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\PgTestDbConfig;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

/**
 * Integration fixture helper for fork tests: which database (WK_FORK_DB = pg | mariadb),
 * which server session a connection is, and a marker file (WK_MARKER) to report to.
 */
final class ForkProbe
{
    /** @return class-string */
    public static function config(): string
    {
        return getenv('WK_FORK_DB') === 'pg' ? PgTestDbConfig::class : MariadbTestDbConfig::class;
    }

    /** The server-side session id of this process's connection — or why there is none. */
    public static function session(): string
    {
        $sql = getenv('WK_FORK_DB') === 'pg' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()';
        try {
            return 'session ' . PpaConnectionPool::db(self::config())->query($sql)->fetchColumn();
        } catch (\Throwable $e) {
            return 'FAILED ' . strtok($e->getMessage(), "\n");
        }
    }

    public static function mark(string $line): void
    {
        file_put_contents((string) getenv('WK_MARKER'), $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
