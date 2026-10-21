<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Attribute;

/**
 * Caches the result of a method — the analogue of Spring's `@Cacheable`.
 *
 * The first call runs the body and stores its result under the key; every later
 * call with the same key returns the stored copy without running the body. The
 * cache is one declared in a {@see \Flytachi\Winter\Kernel\Cache\CacheConfigurer}.
 *
 * ```
 * #[Cacheable('products', key: '{id}', ttl: 300)]
 * public function find(int $id): ?Product
 * ```
 *
 * ---
 * ### Key
 *
 * A template over the parameter names: `'{id}'`, `'{category}:{page}'`, and public
 * properties through a dot, `'{filter.region}'`. Without one, the key is built from
 * every argument, and a method without parameters has a single constant key. The key
 * does not include the method name, so two methods sharing a cache share entries —
 * which is what lets `#[CachePut]` and `#[CacheEvict]` on other methods reach them.
 *
 * ---
 * ### Contract
 *
 * Checked when the proxy is generated, not at runtime:
 *
 * - the cache is declared (or named by {@see CacheConfig} on the class);
 * - the method is `public` or `protected`, not `static` and not `final`, and the
 *   class is not `final`;
 * - it returns something storable — not `void`, `never`, `Generator`, `Closure`,
 *   `callable` or `iterable`;
 * - no parameter is passed by reference: on a hit the body does not run, so it
 *   could not write to it.
 *
 * Only instances obtained from the DI container are proxied, and nothing happens
 * unless the application declares {@see \Flytachi\Winter\Kernel\App\Attribute\EnableCaching}.
 *
 * @link https://winterframe.net/docs/caching#cacheable Return from the cache or compute
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Cacheable
{
    /**
     * @param string|null $cache Name of the cache; null takes the one from #[CacheConfig].
     * @param string|null $key Key template; null builds the key from every argument.
     * @param int|null $ttl Lifetime in seconds; 0 never expires, null takes #[CacheConfig], then the cache's own.
     * @param bool $sync Whether concurrent misses on one key in a worker wait for a single computation.
     * @param bool $cacheNull Whether a null result is stored too.
     */
    public function __construct(
        public readonly ?string $cache = null,
        public readonly ?string $key = null,
        public readonly ?int $ttl = null,
        public readonly bool $sync = false,
        public readonly bool $cacheNull = true,
    ) {
    }
}
