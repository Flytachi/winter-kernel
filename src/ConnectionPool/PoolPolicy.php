<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\ConnectionPool;

/**
 * Immutable pool tuning — the HikariCP-style knobs. A connection pool trades
 * per-request freshness for reuse, so it needs explicit policy to stay resilient:
 * how many connections, how long to wait, when to retire, and when to re-check
 * liveness.
 *
 * ```
 * $policy = new PoolPolicy(maximumPoolSize: 20, maxLifetime: 600);
 * $policy = PoolPolicy::default();
 * ```
 */
final readonly class PoolPolicy
{
    /**
     * @param int $maximumPoolSize Upper bound on connections held per pool.
     * @param float $connectionTimeout Seconds to wait for a free connection before
     *   failing with {@see PoolException} (HikariCP `connectionTimeout`).
     * @param float $maxLifetime Seconds after which a connection is retired and
     *   reopened regardless of health, so none grows stale (`0` = never).
     * @param float $aliveBypassWindow Seconds: a connection idle for less than this
     *   skips the liveness probe on borrow — hot connections pay nothing (HikariCP
     *   `aliveBypassWindow`, default 500ms).
     * @param float $maxLifetimeJitter Fraction `0..1` of random spread applied to
     *   `maxLifetime` so connections don't all expire at the same instant.
     */
    public function __construct(
        public int $maximumPoolSize = 10,
        public float $connectionTimeout = 15.0,
        public float $maxLifetime = 1800.0,
        public float $aliveBypassWindow = 0.5,
        public float $maxLifetimeJitter = 0.1,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }
}
