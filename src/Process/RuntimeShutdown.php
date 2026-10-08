<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Registry of releases run when a process's coroutine runtime is done — the process-side
 * counterpart of the HTTP worker's `workerExit`.
 *
 * Under Swoole a process body runs inside `Coroutine\run()`, which returns only once
 * nothing is left on the reactor. A resource that keeps something there for its own
 * upkeep — a connection pool's repeating housekeeping timer above all — would hold the
 * scheduler open after the body has finished, and the process would never end: it has
 * no server to stop it, and the engine disarms its own grace timer on the way out.
 * Framework packages register their shutdown here at bootstrap; the engine runs them
 * once the body and every task it spawned have finished.
 *
 * A handler must be safe to call when its resource was never used, and must leave the
 * resource usable afterwards (reopened lazily) — the process may still reach it from
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::onShutdown()}.
 */
final class RuntimeShutdown
{
    /** @var array<callable> */
    private static array $handlers = [];

    /** Static-only registry — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Registers a release to run when a process's coroutine runtime ends. Call once at
     * bootstrap.
     */
    public static function register(callable $handler): void
    {
        self::$handlers[] = $handler;
    }

    /**
     * Runs every registered release. A throwing handler is swallowed so one bad release
     * never keeps the others — or the process — from ending.
     */
    public static function runAll(): void
    {
        foreach (self::$handlers as $handler) {
            try {
                $handler();
            } catch (\Throwable) {
                // best-effort — a failing release must not keep the process alive
            }
        }
    }

    /**
     * Clears all handlers (mainly for tests).
     */
    public static function clear(): void
    {
        self::$handlers = [];
    }
}
