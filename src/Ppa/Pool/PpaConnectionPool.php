<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Pool;

use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Kernel\ConnectionPool\ConnectionPool;
use Flytachi\Winter\Kernel\ConnectionPool\PoolEntry;
use Flytachi\Winter\Kernel\ConnectionPool\PoolException;
use Flytachi\Winter\Kernel\ConnectionPool\PoolPolicy;
use Flytachi\Winter\Kernel\ConnectionPool\SingleConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PpaConnectionPool — driver-agnostic connection pool for FPM and Swoole.
 *
 * ## FPM (no coroutines)
 * Behaves identically to the original CDO `ConnectionPool`:
 * one `CDO` instance per config class per process, reused for the entire request.
 *
 * ## Swoole (coroutines)
 * Uses the framework's {@see ConnectionPool} (a HikariCP-inspired pool over a
 * `Swoole\Coroutine\Channel`) per config class. Connections are created **lazily** —
 * only when first requested, up to `poolMaxConnections`. On the **first** `db()` call
 * inside a coroutine one connection is borrowed and cached in the coroutine context;
 * a `defer` returns it automatically when the coroutine ends — no manual release
 * anywhere in the codebase.
 *
 * Unlike a plain `Swoole\ConnectionPool` (a dumb channel), {@see ConnectionPool}
 * actively keeps connections usable across a database outage: a connection idle beyond
 * `aliveBypassWindow` is probed on borrow ({@see CdoConnectionFactory::validate()} →
 * `ping()`) and a dead one is retired for a fresh socket, and a connection older than
 * `maxLifetime` is rotated before it can go stale — restoring the FPM-era resilience
 * (fresh connection ⇒ self-heal after recovery) without a per-borrow probe on hot
 * connections.
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
     * Swoole: one ConnectionPool per config class.
     * @var array<string, ConnectionPool>
     */
    private static array $pools = [];

    /**
     * Config instances — shared across FPM and Swoole modes.
     * @var array<string, DbConfigInterface>
     */
    private static array $configs = [];

    /**
     * FPM / non-coroutine: one self-maintaining {@see SingleConnection} per config
     * class for the lifetime of the process.
     * @var array<string, SingleConnection>
     */
    private static array $static = [];

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
     * Reports a failure that happened **while using** a borrowed connection, so a dead
     * one is retired instead of being handed to the next caller.
     *
     * Only a genuine connection loss evicts ({@see ConnectionLoss}) — a constraint
     * violation or a syntax error means the connection is healthy and is left alone.
     *
     * The pool deliberately does **not** retry the failed statement. It cannot know
     * what was executed: the break may have happened after the server applied the
     * write, so replaying it could duplicate the effect, and replaying one statement
     * of an interrupted transaction is meaningless. The request fails once; the
     * connection is thrown away, so the next one — including the next query in this
     * same request — gets a healthy connection.
     *
     * @param class-string $configClass Config whose connection failed.
     * @param Throwable $error The failure as thrown by CDO/PDO.
     * @return bool Whether the connection was classified as lost and evicted.
     */
    public static function reportFailure(string $configClass, Throwable $error): bool
    {
        $lost      = ConnectionLoss::isLost($error);
        $undecided = !$lost && ConnectionLoss::isUndecided($error);
        if (!$lost && !$undecided) {
            return false;
        }

        $key = base64_encode($configClass);

        if (Runtime::isSwooleCoroutine()) {
            $ctxKey = 'ppa_cdo_' . $key;
            $ctx    = \Swoole\Coroutine::getContext();
            $held   = $ctx[$ctxKey] ?? null;
            if (!$held instanceof BorrowedConnection) {
                return false;
            }
            $config = $held->entry->resource;
            if ($undecided && $config instanceof DbConfigInterface && CdoConnectionFactory::probe($config)) {
                return false; // the driver was vague but the connection answered
            }
            // Mark for the defer to evict, and drop it from the context so the next
            // query in this same coroutine borrows a fresh connection.
            $held->dead = true;
            unset($ctx[$ctxKey]);
            self::logger()->warning("evict: {$configClass} (connection lost in use)");

            return true;
        }

        // Static (FPM / non-coroutine) path: close now, reopen lazily on next use.
        if (!isset(self::$static[$key])) {
            return false;
        }
        $config = self::$static[$key]->peek();
        if ($undecided && $config instanceof DbConfigInterface && CdoConnectionFactory::probe($config)) {
            return false;
        }
        self::$static[$key]->evict();
        self::logger()->warning("evict: {$configClass} (connection lost in use)");

        return true;
    }

    /**
     * Live utilisation of every Swoole coroutine pool, keyed by config FQCN — the
     * HikariCP-style view (active / idle / total vs maximum) for `/actuator/health`.
     *
     * These numbers are **per worker**: each Swoole worker holds its own in-memory
     * pool (as HikariCP is per-JVM), so a health request reflects the worker that
     * served it. The static FPM/non-coroutine path has no pool and is not reported.
     *
     * @return array<string, array{total: int, idle: int, active: int, maximum: int}>
     */
    public static function stats(): array
    {
        $out = [];
        foreach (self::$pools as $key => $pool) {
            $out[base64_decode($key)] = $pool->stats();
        }
        return $out;
    }

    /**
     * Drops every cached connection, pool and config so the next `db()` opens
     * fresh sockets — the fork-safety reset.
     *
     * A fork copies file descriptors, so any connection cached before the fork
     * would be shared with the parent and corrupt the wire protocol. A forked
     * daemon worker runs this via {@see \Flytachi\Winter\Kernel\Process\ForkReset}
     * (registered in {@see \Flytachi\Winter\Kernel\Kernel::init()}), then re-opens
     * lazily in the child. Because access is static — repositories call
     * `PpaConnectionPool::db()`, never an injected instance — clearing the caches
     * is a complete "reconnect": nothing holds a stale reference.
     *
     * Keep connections lazy (do not query from a supervisor before it forks
     * workers) so this stays a cheap no-op in the common case.
     */
    /**
     * Closes every pool and connection this process owns — the worker-shutdown
     * counterpart of {@see reset()}.
     *
     * The difference matters. {@see reset()} is for a **forked child**, which must
     * forget inherited sockets without closing them. Here the process genuinely owns
     * them, so they are closed properly; just as importantly, closing a pool releases
     * its housekeeping timer, and a live timer would keep the worker's reactor from
     * draining until Swoole force-kills it.
     */
    public static function shutdown(): void
    {
        foreach (self::$pools as $pool) {
            $pool->close();
        }
        foreach (self::$static as $connection) {
            $connection->close();
        }
        self::$pools = [];
        self::$static = [];
        self::$configs = [];
    }

    public static function reset(): void
    {
        // Abandon (never close) each pool first: a housekeeping Timer::tick callback
        // holds a reference to its pool, so a pool that is merely dereferenced would
        // stay alive and keep maintaining connections this process no longer owns.
        // abandon() clears that timer without touching the inherited sockets.
        foreach (self::$pools as $pool) {
            $pool->abandon();
        }
        self::$pools = [];
        self::$static = [];
        self::$configs = [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * FPM / non-coroutine path: one {@see SingleConnection} per config class for the
     * process lifetime. For a short FPM request the connection is freshly opened, so
     * the liveness checks are near no-ops; for a long-running non-coroutine process
     * (e.g. a Sync daemon querying the DB) the same idle-gate + maxLifetime that the
     * coroutine pool applies keep the connection healthy across a DB outage.
     */
    private static function staticDb(string $configClass): CDO
    {
        $key = base64_encode($configClass);
        if (!isset(self::$static[$key])) {
            // Register the config for diagnostics (showDbConfigs) and future static knobs.
            self::getConfigDb($configClass);
            self::$static[$key] = new SingleConnection(
                new CdoConnectionFactory($configClass, self::logger()),
                new PoolPolicy(),
            );
            self::logger()->debug("FPM connection opened: {$configClass}");
        }

        /** @var DbConfigInterface $config */
        $config = self::$static[$key]->get();
        return $config->connection();
    }

    /**
     * Swoole path: borrow one connection from the {@see ConnectionPool} on the first
     * call in this coroutine, cache the {@see PoolEntry} in coroutine context, and
     * auto-release via defer when the coroutine ends. The pool validates idle
     * connections and rotates aged ones on borrow (see the class docblock).
     */
    private static function coroutineDb(string $configClass): CDO
    {
        $ctxKey = 'ppa_cdo_' . base64_encode($configClass);
        $ctx    = \Swoole\Coroutine::getContext();

        if (!isset($ctx[$ctxKey])) {
            $pool = self::pool($configClass);
            $cid  = \Swoole\Coroutine::getCid();
            self::logger()->debug("cid={$cid} borrow: {$configClass}");

            try {
                $entry = $pool->borrow();
            } catch (PoolException $e) {
                self::logger()->error("cid={$cid} borrow failed: {$configClass} — {$e->getMessage()}");
                throw new PpaPoolException(
                    "PpaConnectionPool: connection failed for [{$configClass}] — {$e->getMessage()}",
                    previous: $e
                );
            }

            $held = new BorrowedConnection($entry);
            $ctx[$ctxKey] = $held;

            // Auto-return when the coroutine finishes (normal exit OR exception).
            // $held is captured directly — safer than reading from $ctx during teardown —
            // and carries the verdict {@see reportFailure()} may have left on it.
            \Swoole\Coroutine::defer(static function () use ($pool, $held, $cid, $configClass): void {
                if ($held->dead) {
                    self::logger()->warning("cid={$cid} evict: {$configClass} (connection lost in use)");
                    $pool->evict($held->entry);
                    return;
                }
                self::logger()->debug("cid={$cid} release: {$configClass}");
                $pool->release($held->entry);
            });
        }

        /** @var BorrowedConnection $held */
        $held = $ctx[$ctxKey];
        /** @var DbConfigInterface $config */
        $config = $held->entry->resource;
        $cdo    = $config->connection();

        $driver = $cdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!empty($driver)) {
            $cdo->applyDatabaseTimezone($driver, date_default_timezone_get());
        }

        return $cdo;
    }

    /**
     * Returns (and lazily creates) the {@see ConnectionPool} for the given config class.
     *
     * The {@see CdoConnectionFactory} opens one independent CDO per slot (own socket).
     * The pool is lazy: it opens a connection only when a slot is needed (up to
     * `maximumPoolSize`). Sizing/timeout come from {@see PpaPoolConfigInterface} when
     * the config implements it; `maxLifetime`/`aliveBypassWindow` use the
     * {@see PoolPolicy} defaults.
     */
    private static function pool(string $configClass): ConnectionPool
    {
        $key = base64_encode($configClass);
        if (!isset(self::$pools[$key])) {
            $config = self::getConfigDb($configClass);
            $policy = $config instanceof PpaPoolConfigInterface
                ? new PoolPolicy(
                    maximumPoolSize: $config->getPoolMaxConnections(),
                    connectionTimeout: $config->getPoolWaitTimeout(),
                    keepaliveTime: $config->getKeepaliveTime(),
                    idleTimeout: $config->getIdleTimeout(),
                    minimumIdle: $config->getMinimumIdle(),
                )
                : new PoolPolicy(maximumPoolSize: self::DEFAULT_POOL_SIZE, connectionTimeout: 3.0);

            self::logger()->debug("pool created: {$configClass} maxConnections={$policy->maximumPoolSize}");

            self::$pools[$key] = new ConnectionPool(
                new CdoConnectionFactory($configClass, self::logger()),
                $policy,
            );
        }
        return self::$pools[$key];
    }
}
