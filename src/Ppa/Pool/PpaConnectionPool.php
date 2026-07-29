<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Pool;

use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Base\Runtime;
use Psr\Log\LoggerInterface;

/**
 * PpaConnectionPool — driver-agnostic connection pool for FPM and Swoole.
 *
 * ## FPM (no coroutines)
 * Behaves identically to the original CDO `ConnectionPool`:
 * one `CDO` instance per config class per process, reused for the entire request.
 *
 * ## Swoole (coroutines)
 * Uses {@see \Swoole\ConnectionPool} (which wraps `Swoole\Coroutine\Channel`)
 * per config class.  Connections are created **lazily** — only when first requested,
 * up to `poolMaxConnections`.  On the **first** `db()` call inside a coroutine one
 * CDO is borrowed and cached in the coroutine context; a `defer` returns it
 * automatically when the coroutine ends — no manual release anywhere in the codebase.
 *
 * Dead connections: a pooled connection can die while it sits idle in the pool —
 * the server recycles it (`server_lifetime` / idle timeout), a network blip drops
 * it, or a connection pooler in front of the database (e.g. PgBouncer) closes the
 * upstream. `Swoole\ConnectionPool` does not detect this, so every borrow is
 * validated with a trivial round-trip; a dead one is discarded (`put(null)` opens
 * a fresh slot) and re-borrowed. Without this, a recycled connection is handed to
 * the next coroutine and surfaces as an intermittent SSL error or read timeout.
 *
 * ## Pool size
 * Configs that implement {@see PpaPoolConfigInterface} (via {@see PpaPoolTrait})
 * control `poolMaxConnections` and `poolWaitTimeout`.
 * Configs that do NOT implement the interface default to {@see DEFAULT_POOL_SIZE}
 * connections (Swoole only — FPM uses a single {@see $static} CDO and never
 * touches the pool). The default is a modest middle ground: it unblocks
 * coroutine concurrency without letting `worker_num × poolMax × instances`
 * exhaust the database connection limit. Deployments with high concurrency
 * should implement {@see PpaPoolConfigInterface} and tune the size against
 * their database `max_connections`.
 *
 * ## Works with every CDO driver
 * The pool operates on `CDO` objects produced by `DbConfigInterface::connection()`,
 * so it is driver-agnostic — pgsql, mysql, oci, sqlite — anything CDO supports.
 */
final class PpaConnectionPool
{
    /**
     * Default Swoole pool size for configs that don't implement PpaPoolConfigInterface.
     * Modest by design: keeps `worker_num × poolMax × instances` within typical
     * database connection limits while still allowing coroutine concurrency.
     */
    private const int DEFAULT_POOL_SIZE = 5;

    /**
     * How many times a borrowed-but-dead connection is discarded and reopened
     * before giving up. Each `put(null)` opens a brand-new socket, so one retry is
     * normally enough; the small margin covers several stale slots in a row.
     */
    private const int MAX_STALE_RETRIES = 2;

    /**
     * Seconds a static (non-coroutine) connection may sit idle before the next
     * handout re-probes it. Rapid successive calls (an FPM request) stay under this
     * and skip the probe; a long-running process that was idle longer gets its cached
     * connection validated — and transparently reopened if the server recycled it.
     */
    private const float STATIC_VALIDATE_IDLE = 1.0;

    /**
     * Swoole: one ConnectionPool per config class.
     * @var array<string, \Swoole\ConnectionPool>
     */
    private static array $pools = [];

    /**
     * Config instances — shared across FPM and Swoole modes.
     * @var array<string, DbConfigInterface>
     */
    private static array $configs = [];

    /**
     * FPM: one CDO per config class for the lifetime of the process/request.
     * @var array<string, CDO>
     */
    private static array $static = [];

    /**
     * Monotonic seconds a static CDO was last handed out — drives idle re-validation.
     * @var array<string, float>
     */
    private static array $staticLastUsed = [];

    private static function logger(): LoggerInterface
    {
        return LoggerFactory::getLogger('PPA');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the initialised config for the given class.
     *
     * Instantiates and calls `setUp()` on first access; returns the cached
     * instance on subsequent calls.  Replaces `ConnectionPool::getConfigDb()`.
     */
    public static function getConfigDb(string $configClass): DbConfigInterface
    {
        $key = base64_encode($configClass);
        if (!isset(self::$configs[$key])) {
            /** @var DbConfigInterface $config */
            $config = new $configClass();
            $config->setLogger(self::logger());
            $config->setUp();
            self::$configs[$key] = $config;
            self::logger()->debug("config registered: {$configClass} driver={$config->getDriver()}");
        }
        return self::$configs[$key];
    }

    /**
     * Returns an active CDO connection for the given config class.
     *
     * - **FPM**: process-level singleton CDO (identical to original behaviour).
     * - **Swoole**: borrows one CDO from {@see \Swoole\ConnectionPool} on the
     *   first call per coroutine, caches it in coroutine context, and registers
     *   a `defer` to return it automatically when the coroutine ends.
     *
     * Replaces `ConnectionPool::db()`.
     *
     * @throws PpaPoolException When the pool is exhausted within the configured timeout.
     */
    public static function db(string $configClass): CDO
    {
        if (!Runtime::isSwooleCoroutine()) {
            return self::staticDb($configClass);
        }

        return self::coroutineDb($configClass);
    }

    /**
     * Returns all currently registered config instances (diagnostics / health checks).
     *
     * @return DbConfigInterface[]
     */
    public static function showDbConfigs(): array
    {
        return self::$configs;
    }

    /**
     * Drops every cached connection, pool and config so the next `db()` opens
     * fresh sockets — the fork-safety reset.
     *
     * A fork copies file descriptors, so any connection cached before the fork
     * would be shared with the parent and corrupt the wire protocol. A forked
     * daemon worker runs this via {@see \Flytachi\Winter\K2\Process\ForkReset}
     * (registered in {@see \Flytachi\Winter\K2\Kernel::init()}), then re-opens
     * lazily in the child. Because access is static — repositories call
     * `PpaConnectionPool::db()`, never an injected instance — clearing the caches
     * is a complete "reconnect": nothing holds a stale reference.
     *
     * Keep connections lazy (do not query from a supervisor before it forks
     * workers) so this stays a cheap no-op in the common case.
     */
    public static function reset(): void
    {
        self::$pools = [];
        self::$static = [];
        self::$staticLastUsed = [];
        self::$configs = [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Non-coroutine path (FPM, CLI, a Sync-engine process): one CDO per config class,
     * cached for the process lifetime.
     *
     * Under FPM the process is short-lived, so the connection cannot go stale within a
     * request. A long-running process, however, can outlive its connection — the
     * server or a pooler (PgBouncer) recycles it while idle — so after an idle gap
     * ({@see STATIC_VALIDATE_IDLE}) the cached connection is probed with {@see isAlive()}
     * and transparently reopened if dead. Rapid successive calls skip the probe, so the
     * FPM hot path pays nothing.
     */
    private static function staticDb(string $configClass): CDO
    {
        $key = base64_encode($configClass);
        $now = hrtime(true) / 1e9;

        if (isset(self::$static[$key])) {
            $idle = $now - (self::$staticLastUsed[$key] ?? $now);
            if ($idle < self::STATIC_VALIDATE_IDLE || self::isAlive(self::$static[$key])) {
                self::$staticLastUsed[$key] = $now;
                return self::$static[$key];
            }
            self::logger()->warning("static connection stale, reconnecting: {$configClass}");
            unset(self::$static[$key]);
        }

        self::$static[$key] = self::getConfigDb($configClass)->connection();
        self::$staticLastUsed[$key] = $now;
        self::logger()->debug("connection opened: {$configClass}");
        return self::$static[$key];
    }

    /**
     * Swoole path: borrow from Swoole\ConnectionPool on first call in this coroutine,
     * cache in coroutine context, auto-release via defer on coroutine end.
     */
    private static function coroutineDb(string $configClass): CDO
    {
        $ctxKey = 'ppa_cdo_' . base64_encode($configClass);
        $ctx    = \Swoole\Coroutine::getContext();

        if (!isset($ctx[$ctxKey])) {
            $swPool  = self::swPool($configClass);
            $config  = self::getConfigDb($configClass);
            $timeout = $config instanceof PpaPoolConfigInterface
                ? $config->getPoolWaitTimeout()
                : 3.0;

            $cid = \Swoole\Coroutine::getCid();
            self::logger()->debug("cid={$cid} borrow: {$configClass}");

            $cdo = self::borrowLive($swPool, $timeout, $configClass, $cid);

            $ctx[$ctxKey] = $cdo;

            // Auto-return when the coroutine finishes (normal exit OR exception).
            // $cdo is captured directly — safer than reading from $ctx during teardown.
            \Swoole\Coroutine::defer(static function () use ($swPool, $cdo, $cid, $configClass): void {
                self::logger()->debug("cid={$cid} release: {$configClass}");
                $swPool->put($cdo);
            });
        }

        $driver = $ctx[$ctxKey]->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!empty($driver)) {
            $ctx[$ctxKey]->applyDatabaseTimezone($driver, date_default_timezone_get());
        }

        return $ctx[$ctxKey];
    }

    /**
     * Borrows a connection from the pool and guarantees it is live. A pooled
     * connection may have died while idle (server recycle / idle timeout / a pooler
     * like PgBouncer closing the upstream), which `Swoole\ConnectionPool` cannot
     * detect. Each borrow is probed with {@see isAlive()}; a dead one is discarded —
     * `put(null)` decrements the slot and opens a fresh socket — and re-borrowed, up
     * to {@see MAX_STALE_RETRIES} times.
     *
     * @throws PpaPoolException On connect failure, pool exhaustion, or if no live
     *   connection can be obtained within the retry budget.
     */
    private static function borrowLive(
        \Swoole\ConnectionPool $swPool,
        float $timeout,
        string $configClass,
        int $cid,
    ): CDO {
        for ($attempt = 0; $attempt <= self::MAX_STALE_RETRIES; ++$attempt) {
            try {
                /** @var CDO|false $cdo */
                $cdo = $swPool->get($timeout);
            } catch (\Throwable $e) {
                self::logger()->error("cid={$cid} connect failed: {$configClass} — {$e->getMessage()}");
                throw new PpaPoolException(
                    "PpaConnectionPool: connection failed for [{$configClass}] — {$e->getMessage()}",
                    previous: $e
                );
            }

            if ($cdo === false) {
                self::logger()->error("cid={$cid} exhausted: {$configClass} (timeout={$timeout}s)");
                throw new PpaPoolException(
                    "PpaConnectionPool: no free connection for [{$configClass}] "
                    . "within {$timeout}s — increase poolMaxConnections or poolWaitTimeout"
                );
            }

            if (self::isAlive($cdo)) {
                return $cdo;
            }

            // Dead connection (idle-recycled by the server / pooler): discard it and
            // let the pool open a fresh one. put(null) makes a new socket into the slot.
            self::logger()->warning("cid={$cid} stale connection discarded, reopening: {$configClass}");
            try {
                $swPool->put(null);
            } catch (\Throwable $e) {
                self::logger()->error("cid={$cid} reconnect failed: {$configClass} — {$e->getMessage()}");
                throw new PpaPoolException(
                    "PpaConnectionPool: reconnect failed for [{$configClass}] — {$e->getMessage()}",
                    previous: $e
                );
            }
        }

        throw new PpaPoolException(
            "PpaConnectionPool: could not obtain a live connection for [{$configClass}] "
            . "after " . (self::MAX_STALE_RETRIES + 1) . " attempts"
        );
    }

    /**
     * Lightweight liveness probe: a trivial round-trip that fails on a dead socket.
     * The driver name is cached on the CDO (no round-trip), so this is safe to call
     * on a dead connection. In non-debug mode PDO returns `false` on failure instead
     * of throwing, so both a `false` result and a thrown error mean "dead".
     */
    private static function isAlive(CDO $cdo): bool
    {
        try {
            $sql = $cdo->getDriverName() === 'oci' ? 'SELECT 1 FROM DUAL' : 'SELECT 1';
            return $cdo->query($sql) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns (and lazily creates) the Swoole\ConnectionPool for the given config class.
     *
     * The factory callable passed to Swoole\ConnectionPool creates a fresh CDO
     * from a dedicated config instance per slot — guaranteeing independent sockets.
     * Swoole\ConnectionPool itself is lazy: it calls the factory only when a slot
     * is needed (up to `poolMaxConnections`).
     */
    private static function swPool(string $configClass): \Swoole\ConnectionPool
    {
        $key = base64_encode($configClass);
        if (!isset(self::$pools[$key])) {
            $config  = self::getConfigDb($configClass);
            $maxConn = $config instanceof PpaPoolConfigInterface
                ? $config->getPoolMaxConnections()
                : self::DEFAULT_POOL_SIZE;

            self::logger()->debug("pool created: {$configClass} maxConnections={$maxConn}");

            // Factory: each call creates one independent CDO (own socket).
            $factory = static function () use ($configClass): CDO {
                /** @var DbConfigInterface $slotConfig */
                $slotConfig = new $configClass();
                $slotConfig->setUp();
                $slotConfig->setLogger(self::logger());
                $cdo = $slotConfig->connection();
                self::logger()->debug("slot opened: {$configClass} dsn={$slotConfig->getDns()}");
                return $cdo;
            };

            self::$pools[$key] = new \Swoole\ConnectionPool($factory, $maxConn);
        }
        return self::$pools[$key];
    }
}
