<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Pool;

/**
 * Default pool-settings implementation for {@see PpaPoolConfigInterface}.
 *
 * The trait provides only methods with built-in defaults — it does NOT declare
 * properties, so the class can freely define them with any value without
 * triggering a PHP 8.3 trait-property conflict fatal error.
 *
 * ```php
 * class MainDbConfig extends PgDbConfig implements PpaPoolConfigInterface
 * {
 *     use PpaPoolTrait;
 *
 *     public int   $poolMaxConnections = 10;  // override default (5)
 *     public float $poolWaitTimeout    = 5.0; // override default (3.0)
 * }
 * ```
 *
 * @property int   $poolMaxConnections Maximum number of CDO connections in the pool (default: 5).
 * @property float $poolWaitTimeout    Seconds to wait for a free slot before {@see PpaPoolException} (default: 3.0).
 */
trait PpaPoolTrait
{
    public function getPoolMaxConnections(): int
    {
        return $this->poolMaxConnections ?? 5;
    }

    public function getPoolWaitTimeout(): float
    {
        return $this->poolWaitTimeout ?? 3.0;
    }
}
