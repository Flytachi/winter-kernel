<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

/**
 * Enables method caching — the analogue of Spring's `@EnableCaching`. Declared on
 * the {@see \Flytachi\Winter\Kernel\WinterApplication} class.
 *
 * Without it the cache attributes do nothing: their classes are not proxied and
 * every call runs the body. With it, the caches declared by
 * {@see \Flytachi\Winter\Kernel\Cache\CacheConfigurer} are created at boot and the
 * annotated classes are proxied.
 *
 * `CACHE_ENABLED=false` in the environment switches caching off without a code
 * change — read at boot, so it takes a restart — for when a cache is suspected of
 * serving wrong data in production.
 *
 * ```
 * #[EnableCaching]
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/caching Caching method results
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableCaching
{
}
