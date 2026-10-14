<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

use Flytachi\Winter\Kernel\Cache\Store\CacheStore;
use Flytachi\Winter\Kernel\Cache\Store\MemoryStore;
use Flytachi\Winter\Kernel\Cache\Store\TableStore;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * The caches of the running application, by name.
 *
 * Configured once at boot from the {@see CacheConfigurer} declarations. Stores are
 * created on first use, except that {@see allocate()} creates them all at once —
 * the application calls it in the master right before workers fork, because a
 * `Swoole\Table` is shared only with the processes forked after it exists. A
 * console command never calls it, so `call make …` does not reserve the tables.
 *
 * Until it is configured, and whenever `CACHE_ENABLED=false`, caching is off: the
 * proxies run every body and evictions do nothing.
 */
final class CacheManager
{
    /** Least time between two warnings about the same cache, in seconds. */
    private const int WARNING_INTERVAL = 60;

    /** @var array<string, CacheDefinition> */
    private static array $definitions = [];

    /** @var array<string, CacheStore> */
    private static array $stores = [];

    private static bool $enabled = false;

    /** @var array<string, array{at: int, skipped: int}> Last warning per cache and writes skipped since. */
    private static array $skips = [];

    private function __construct()
    {
    }

    /**
     * Replaces the declared caches.
     *
     * @param array<string, CacheDefinition> $definitions Keyed by name.
     * @param bool $enabled False keeps the declarations but switches caching off.
     */
    public static function configure(array $definitions, bool $enabled = true): void
    {
        self::$definitions = $definitions;
        self::$stores = [];
        self::$skips = [];
        self::$enabled = $enabled;
    }

    /**
     * Whether cached methods consult their caches at all.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Whether a cache of this name is declared.
     */
    public static function has(string $name): bool
    {
        return isset(self::$definitions[$name]);
    }

    /**
     * Returns every declared cache.
     *
     * @return array<string, CacheDefinition>
     */
    public static function definitions(): array
    {
        return self::$definitions;
    }

    /**
     * Creates every store now.
     *
     * Call in the process the workers fork from, before they do: a table created
     * later would exist in one process only. Does nothing while caching is off,
     * so `CACHE_ENABLED=false` reserves no memory.
     */
    public static function allocate(): void
    {
        if (!self::$enabled) {
            return;
        }

        foreach (array_keys(self::$definitions) as $name) {
            self::store($name);
        }
    }

    /**
     * Returns the store of a cache, creating it on first use.
     *
     * @throws CacheException If no cache of this name is declared.
     */
    public static function store(string $name): CacheStore
    {
        if (isset(self::$stores[$name])) {
            return self::$stores[$name];
        }

        $definition = self::$definitions[$name] ?? throw CacheException::of(
            "Cache '{$name}'",
            'it is not declared',
            'Declare it in a CacheConfigurer.'
        );

        return self::$stores[$name] = match ($definition->type) {
            CacheType::Table => new TableStore($definition),
            CacheType::Memory => new MemoryStore($definition),
        };
    }

    /**
     * Removes one entry from a cache — for the cases an attribute cannot express.
     *
     * @param string $cache Cache name.
     * @param string $key Entry key, as the attribute's key template produces it.
     */
    public static function evict(string $cache, string $key): void
    {
        if (self::$enabled) {
            self::store($cache)->evict($key);
        }
    }

    /**
     * Removes every entry from a cache.
     *
     * @param string $cache Cache name.
     */
    public static function clear(string $cache): void
    {
        if (self::$enabled) {
            self::store($cache)->clear();
        }
    }

    /**
     * Forgets the configuration and every store (for tests).
     */
    public static function reset(): void
    {
        self::configure([], false);
    }

    /**
     * Records a write that did not happen, and warns about it at most once a minute
     * per cache.
     *
     * The method's caller gets the right value either way, so this is not an error —
     * but a cache that keeps refusing writes is a cache that silently stopped
     * working, and the database pays for it.
     *
     * @internal Called by the stores and the proxies.
     * @param string $cache Cache name.
     * @param string $reason Why the value was not stored.
     */
    public static function skipped(string $cache, string $reason): void
    {
        $now = time();
        $state = self::$skips[$cache] ?? ['at' => 0, 'skipped' => 0];
        $state['skipped']++;

        if ($now - $state['at'] >= self::WARNING_INTERVAL) {
            try {
                LoggerFactory::getLogger(self::class)->warning(sprintf(
                    "Cache '%s' skipped a write: %s. The value was returned uncached; "
                    . '%d write(s) skipped since the last warning.',
                    $cache,
                    $reason,
                    $state['skipped'],
                ), ['cache' => $cache, 'skipped' => $state['skipped']]);
            } catch (\Throwable) {
                // A warning that cannot be logged must not fail the call it is about.
            }
            $state = ['at' => $now, 'skipped' => 0];
        }

        self::$skips[$cache] = $state;
    }
}
