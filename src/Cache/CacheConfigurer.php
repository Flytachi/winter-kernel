<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

/**
 * Declares the caches the cache attributes refer to. Any implementing class is
 * discovered on scan and invoked at boot, when the application declares
 * {@see \Flytachi\Winter\Kernel\App\Attribute\EnableCaching}.
 *
 * ```
 * final class CacheConfig implements CacheConfigurer
 * {
 *     public function configureCaches(Caches $caches): void
 *     {
 *         $caches
 *             ->table('settings')
 *             ->memory('stock', maxItems: 1_000, ttl: 30)
 *             ->file('api-token', ttl: 3300)
 *             ->redis('products', MainRedisConfig::class, ttl: 300);
 *     }
 * }
 * ```
 *
 * @link https://winterframe.net/docs/caching Declaring caches
 */
interface CacheConfigurer
{
    public function configureCaches(Caches $caches): void;
}
