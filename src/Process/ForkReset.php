<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Registry of resets run in a freshly forked worker, before its body.
 *
 * A fork copies the parent's memory, so any inherited resource backed by a file
 * descriptor — a DB connection, a pool, a socket — is shared across processes
 * and corrupts if used from more than one. Framework packages register a reset
 * here at bootstrap (e.g. a connection pool registers a reconnect); the process
 * runtime runs them in the child via {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::afterFork()}.
 *
 * A reset MUST reconnect **in place** (close the old fd, open a new one on the
 * same object) rather than replace the object — otherwise already-injected
 * `#[Autowired]` references keep pointing at the stale instance. A lazily-opened
 * resource needs no handler at all (there is nothing to reset yet).
 */
final class ForkReset
{
    /** @var array<callable> */
    private static array $handlers = [];

    /** Static-only registry — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Registers a reset to run in every forked worker. Call once at bootstrap.
     */
    public static function register(callable $handler): void
    {
        self::$handlers[] = $handler;
    }

    /**
     * Runs every registered reset. A throwing handler is swallowed so one bad
     * reset never aborts worker boot or blocks the others.
     */
    public static function runAll(): void
    {
        foreach (self::$handlers as $handler) {
            try {
                $handler();
            } catch (\Throwable) {
                // best-effort — a failing reset must not abort worker boot
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
