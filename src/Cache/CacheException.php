<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

use Flytachi\Winter\Kernel\Core\Proxy\ProxyException;

/**
 * Thrown when the cache attributes or the cache declarations break their contract
 * — an undeclared cache, a key naming a parameter that does not exist, a result
 * that cannot be stored.
 *
 * Raised by `call di build` and at boot, never while serving traffic: a typo in a
 * cache name would otherwise show up only as load on the database.
 */
class CacheException extends ProxyException
{
}
