<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Attribute;

/**
 * Class-level defaults for the cache attributes of its methods — the analogue of
 * Spring's `@CacheConfig`.
 *
 * ```
 * #[CacheConfig('products', ttl: 300)]
 * class ProductService
 * {
 *     #[Cacheable(key: '{id}')]
 *     public function find(int $id): ?Product
 * }
 * ```
 *
 * A value given on the method wins over the one given here.
 *
 * @link https://winterframe.net/docs/caching#cacheconfig Class defaults
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CacheConfig
{
    /**
     * @param string|null $cache Cache used by methods that name none.
     * @param int|null $ttl Lifetime in seconds for methods that set none; 0 never expires.
     */
    public function __construct(
        public readonly ?string $cache = null,
        public readonly ?int $ttl = null,
    ) {
    }
}
