<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa;

use Flytachi\Winter\K2\Ppa\Stereotype\CteRepo;

/**
 * EntityCallEdoTrait — Static CteRepo Factory Shortcut
 *
 * Mix into a CDO config class alongside {@see \Flytachi\Winter\Cdo\Config\Common\EntityCallDbTrait}
 * to add a `::cte()` factory that returns a ready-to-use {@see CteRepo} instance
 * bound to the calling config class.
 *
 * ```
 * use Flytachi\Winter\Cdo\Config\Common\EntityCallDbTrait;
 * use Flytachi\Winter\K2\Ppa\EntityCallEdoTrait;
 *
 * class DbConfig extends PgDbConfig
 * {
 *     use EntityCallDbTrait;   // DbConfig::instance() → CDO
 *     use EntityCallEdoTrait;  // DbConfig::cte()      → CteRepo
 *
 *     public function setUp(): void
 *     {
 *         $this->host     = 'db.example.com';
 *         $this->database = 'myapp';
 *         $this->username = 'app';
 *         $this->password = 'secret';
 *     }
 * }
 *
 * // Ad-hoc query without a dedicated repository class:
 * $orders = DbConfig::cte()
 *     ->from('orders o')
 *     ->joinLeft('users u', 'o.user_id = u.id')
 *     ->where(Qb::eq('o.status', 'pending'))
 *     ->orderBy('o.created_at DESC')
 *     ->limit(50)
 *     ->findAll();
 * ```
 *
 * @package Flytachi\Winter\K2\Ppa
 */
trait EntityCallEdoTrait
{
    /**
     * Returns a {@see CteRepo} instance bound to the calling config class.
     *
     * Equivalent to `new CteRepo(static::class)`.
     * Useful for ad-hoc queries that do not belong to a dedicated repository.
     *
     * @return CteRepo
     */
    final public static function cte(): CteRepo
    {
        return new CteRepo(static::class);
    }
}
