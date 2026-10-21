<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Attribute;

/**
 * Removes entries when the method runs — the analogue of Spring's `@CacheEvict`.
 *
 * ```
 * #[CacheEvict('products', key: '{id}')]
 * #[CacheEvict('product-list', allEntries: true)]
 * public function delete(int $id): void
 * ```
 *
 * Repeatable, so one method can clean several caches. It also combines with
 * {@see Cacheable} or {@see CachePut} on the same method.
 *
 * By default the entry is removed after the body returns, and not at all if it
 * throws — a failed change leaves the cached value as valid as it was. With
 * `beforeInvocation: true` it is removed first, whatever happens next.
 *
 * After the body the key may also refer to the returned value as `{result}`.
 * Unlike the caching attributes, a `void` method and parameters passed by
 * reference are fine here.
 *
 * @link https://winterframe.net/docs/caching#cacheevict Removing entries
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class CacheEvict
{
    /**
     * @param string|null $cache Name of the cache; null takes the one from #[CacheConfig].
     * @param string|null $key Key template; null builds the key from every argument.
     * @param bool $allEntries Whether to empty the whole cache instead of one key.
     * @param bool $beforeInvocation Whether to evict before the body runs rather than after it returns.
     */
    public function __construct(
        public readonly ?string $cache = null,
        public readonly ?string $key = null,
        public readonly bool $allEntries = false,
        public readonly bool $beforeInvocation = false,
    ) {
    }
}
