<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Pool;

/**
 * Marks a DbConfig as pool-aware.
 *
 * Mix {@see PpaPoolTrait} into your config class to implement this interface
 * without writing boilerplate.
 *
 * ```php
 * class MainDbConfig extends PgDbConfig implements PpaPoolConfigInterface
 * {
 *     use PpaPoolTrait;
 *
 *     public int   $poolMaxConnections = 10;
 *     public float $poolWaitTimeout    = 5.0;
 *
 *     public function setUp(): void { ... }
 * }
 * ```
 */
interface PpaPoolConfigInterface
{
    public function getPoolMaxConnections(): int;
    public function getPoolWaitTimeout(): float;

    /**
     * Seconds after which the background housekeeper proactively probes an idle
     * connection (retiring dead ones before a borrow sees them). `0` = disabled.
     * Swoole only — has no effect under FPM. See HikariCP `keepaliveTime`.
     */
    public function getKeepaliveTime(): float;

    /**
     * Seconds after which the housekeeper closes an idle connection, shrinking the
     * pool down to {@see getMinimumIdle()}. `0` = never shrink. Swoole only. See
     * HikariCP `idleTimeout`.
     */
    public function getIdleTimeout(): float;

    /**
     * Warm connection floor the housekeeper maintains (reopens up to this count,
     * never shrinks below it). `0` = fully lazy. Swoole only. See HikariCP
     * `minimumIdle`.
     */
    public function getMinimumIdle(): int;
}
