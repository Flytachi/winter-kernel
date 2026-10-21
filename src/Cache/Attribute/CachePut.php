<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Attribute;

/**
 * Always runs the method and stores its result — the analogue of Spring's
 * `@CachePut`.
 *
 * Where {@see Cacheable} skips the body on a hit, this one never does: it is for
 * methods that change the data and should leave the fresh value behind, so the
 * next read does not have to go to the database.
 *
 * ```
 * #[CachePut('products', key: '{result.id}')]
 * public function save(Product $product): Product
 * ```
 *
 * Besides the parameters, the key may refer to the returned value as `{result}`.
 * A null result with `cacheNull: false` removes the entry instead of keeping the
 * old one.
 *
 * The contract is that of {@see Cacheable}; the two cannot be combined on one
 * method.
 *
 * @link https://winterframe.net/docs/caching#cacheput Always run and refresh the cache
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CachePut
{
    /**
     * @param string|null $cache Name of the cache; null takes the one from #[CacheConfig].
     * @param string|null $key Key template, may use {result}; null builds the key from every argument.
     * @param int|null $ttl Lifetime in seconds; 0 never expires, null takes #[CacheConfig], then the cache's own.
     * @param bool $cacheNull Whether a null result is stored; when false it removes the entry.
     */
    public function __construct(
        public readonly ?string $cache = null,
        public readonly ?string $key = null,
        public readonly ?int $ttl = null,
        public readonly bool $cacheNull = true,
    ) {
    }
}
