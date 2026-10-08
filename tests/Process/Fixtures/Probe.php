<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

/** Integration fixture helper: appends a line to the file named by WK_MARKER. */
final class Probe
{
    public static function mark(string $line): void
    {
        $path = getenv('WK_MARKER');
        if ($path !== false && $path !== '') {
            file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** One query, then the pool ceiling this process ended up with. */
    public static function markPoolMaximum(string $who): void
    {
        try {
            PpaConnectionPool::db(ProbeDb::class)->query('SELECT 1');
            self::mark($who . ' maximum=' . PpaConnectionPool::stats()[ProbeDb::class]['maximum']);
        } catch (\Throwable $e) {
            self::mark($who . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
            throw $e;
        }
    }
}
