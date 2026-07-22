<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process\Engine;

/**
 * Picks the {@see ProcessEngine} matching the current runtime.
 *
 * Swoole present → coroutines; otherwise → forks. The choice is made from the
 * loaded extension rather than the booted runtime mode, because a process
 * launched from the CLI creates its own coroutine scheduler via
 * {@see \Swoole\Coroutine\run()} and is not a Swoole server worker.
 *
 * @see \Flytachi\Winter\K2\Concurrent\Executors
 */
final class Engines
{
    private function __construct()
    {
    }

    /**
     * @param int $concurrency Maximum simultaneous tasks; 0 means unlimited.
     * @param float $grace Seconds to wait after a stop request before forcing exit.
     */
    public static function common(int $concurrency, float $grace): ProcessEngine
    {
        return extension_loaded('swoole')
            ? new SwooleEngine($concurrency, $grace)
            : new SyncEngine($concurrency, $grace);
    }
}
