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
 *             ->table('products', rows: 10_000, valueSize: 4 * 1024, ttl: 300)
 *             ->memory('stock', maxItems: 1_000, ttl: 30);
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
