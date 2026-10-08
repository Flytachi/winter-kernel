<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Registry of releases run in the **parent**, right before it forks — the counterpart of
 * {@see ForkReset}, which runs in the child.
 *
 * A fork copies every connection object with its socket, and a child cannot let go of an
 * inherited one quietly: dropping it, or merely exiting, runs the driver's destructor,
 * which closes the server session the parent is still using. So the parent closes its own
 * connections first and the child inherits nothing; both reopen lazily. Framework
 * packages register a release here at bootstrap; the runtime runs them before every
 * `pcntl_fork()` it makes.
 *
 * Unlike {@see ForkReset} and {@see RuntimeShutdown}, a handler may refuse: a connection
 * that cannot be closed without harm — one inside a transaction — makes it throw, and the
 * exception reaches whoever asked for the fork.
 */
final class BeforeFork
{
    /** @var array<callable> */
    private static array $handlers = [];

    /** Static-only registry — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Registers a release to run before every fork. Call once at bootstrap.
     */
    public static function register(callable $handler): void
    {
        self::$handlers[] = $handler;
    }

    /**
     * Runs every registered release, in order — all of them, even after one refused, so
     * whatever can be closed is closed — then rethrows the first refusal.
     *
     * @throws \Throwable Whatever the first refusing handler threw.
     */
    public static function runAll(): void
    {
        $refusal = null;
        foreach (self::$handlers as $handler) {
            try {
                $handler();
            } catch (\Throwable $e) {
                $refusal ??= $e;
            }
        }
        if ($refusal !== null) {
            throw $refusal;
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
