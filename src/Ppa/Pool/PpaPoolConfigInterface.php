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
}