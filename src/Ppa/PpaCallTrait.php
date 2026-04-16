<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\K2\Ppa\Stereotype\CteRepo;

/**
 * PpaCallTrait — PPA-aware static shortcuts for config classes.
 *
 * Replaces {@see \Flytachi\Winter\Cdo\Config\Common\EntityCallDbTrait} so that
 * both `::instance()` and `::cte()` go through {@see PpaConnectionPool} instead
 * of the CDO-bundled `ConnectionPool`.  This ensures FPM singleton behaviour is
 * preserved and Swoole coroutine pooling is applied automatically.
 *
 * Mix this trait into your config class (do NOT also use `EntityCallDbTrait`):
 *
 * ```php
 * class DbConfig extends PgDbConfig implements PpaPoolConfigInterface
 * {
 *     use PpaCallTrait;
 *     use PpaPoolTrait;
 *
 *     public function setUp(): void { ... }
 * }
 *
 * // CDO connection (FPM singleton / Swoole pool slot):
 * DbConfig::instance()->insert('orders', $data);
 *
 * // Ad-hoc query without a dedicated repository:
 * DbConfig::cte()->from('orders o')->where(Qb::eq('status', 'new'))->findAll();
 * ```
 *
 * @package Flytachi\Winter\K2\Ppa
 */
trait PpaCallTrait
{
    /**
     * Returns an active CDO connection for the calling config class.
     *
     * Routes through {@see PpaConnectionPool::db()} — FPM singleton or
     * Swoole coroutine pool slot depending on the runtime context.
     *
     * @return CDO
     */
    final public static function instance(): CDO
    {
        return PpaConnectionPool::db(static::class);
    }

    /**
     * Returns a {@see CteRepo} instance bound to the calling config class.
     *
     * Useful for ad-hoc queries that do not belong to a dedicated repository.
     *
     * @return CteRepo
     */
    final public static function cte(): CteRepo
    {
        return new CteRepo(static::class);
    }
}
