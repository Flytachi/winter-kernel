<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

/**
 * Runtime helper called by generated proxies.
 *
 * Every cached call goes through here, so the decisions that do not depend on
 * the method live here rather than in each proxy: whether caching is on, how a
 * value is copied in and out, how a key part is spelled, and who computes a
 * missing entry when several coroutines ask for it at once.
 *
 * @internal Not part of the public API; generated code depends on it.
 */
final class CacheSupport
{
    /** Separates the arguments of a key built from all of them. */
    private const string ARGUMENT_SEPARATOR = "\x1F";

    /** Stands for null in a key, so it differs from an empty string. */
    private const string NULL_PART = "\0";

    /** @var array<string, \Swoole\Coroutine\Channel> Computations in flight in this worker, for `sync`. */
    private static array $inflight = [];

    private function __construct()
    {
    }

    /**
     * Returns the cached value, or runs the body and caches what it returns.
     *
     * @param string $cache Cache name.
     * @param string $key Entry key.
     * @param int|null $ttl Lifetime in seconds; null takes the cache's own.
     * @param bool $sync Whether concurrent misses on the key in this worker wait for one computation.
     * @param bool $cacheNull Whether a null result is stored.
     * @param \Closure $body Bound call to the original method.
     */
    public static function cacheable(
        string $cache,
        string $key,
        ?int $ttl,
        bool $sync,
        bool $cacheNull,
        \Closure $body,
    ): mixed {
        if (!CacheManager::isEnabled()) {
            return $body();
        }

        $store = CacheManager::store($cache);
        $hit = $store->get($key);
        if ($hit !== null) {
            return unserialize($hit);
        }

        if (!$sync || !self::inCoroutine()) {
            return self::compute($cache, $key, $ttl, $cacheNull, $body);
        }

        // One coroutine computes, the others wait for it and read what it stored.
        // A waiter that still finds nothing — the body threw, or the value was not
        // cacheable — goes round and computes itself.
        $id = $cache . "\0" . $key;
        while (isset(self::$inflight[$id])) {
            self::$inflight[$id]->pop();

            $hit = $store->get($key);
            if ($hit !== null) {
                return unserialize($hit);
            }
        }

        $channel = new \Swoole\Coroutine\Channel(1);
        self::$inflight[$id] = $channel;
        try {
            return self::compute($cache, $key, $ttl, $cacheNull, $body);
        } finally {
            unset(self::$inflight[$id]);
            // Closing wakes every waiter at once.
            $channel->close();
        }
    }

    /**
     * Stores the value a method returned, replacing the entry.
     *
     * @param string $cache Cache name.
     * @param string $key Entry key.
     * @param int|null $ttl Lifetime in seconds; null takes the cache's own.
     * @param bool $cacheNull Whether a null value is stored; when false it removes the entry.
     * @param mixed $value Value the method returned.
     */
    public static function put(string $cache, string $key, ?int $ttl, bool $cacheNull, mixed $value): void
    {
        if (!CacheManager::isEnabled()) {
            return;
        }

        if ($value === null && !$cacheNull) {
            CacheManager::store($cache)->evict($key);

            return;
        }

        self::store($cache, $key, $ttl, $value);
    }

    /**
     * Removes one entry, or all of them.
     *
     * @param string $cache Cache name.
     * @param string|null $key Entry key; null empties the cache.
     */
    public static function evict(string $cache, ?string $key): void
    {
        if (!CacheManager::isEnabled()) {
            return;
        }

        $store = CacheManager::store($cache);
        $key === null ? $store->clear() : $store->evict($key);
    }

    /**
     * Builds the key of a method without a key template, from all its arguments.
     *
     * @param mixed ...$arguments Arguments in declaration order.
     */
    public static function key(mixed ...$arguments): string
    {
        return implode(self::ARGUMENT_SEPARATOR, array_map(self::part(...), $arguments));
    }

    /**
     * Spells one value as part of a key.
     *
     * @param mixed $value Argument, or a property reached through one.
     * @throws CacheException If the value has no stable spelling.
     */
    public static function part(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => self::NULL_PART,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \Stringable => (string) $value,
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => throw CacheException::of(
                'Cache key',
                'a value of type ' . get_debug_type($value) . ' cannot be part of a key',
                'Name a scalar in the key template, e.g. key: \'{product.id}\', or make the class Stringable.'
            ),
        };
    }

    /**
     * Forgets the computations in flight (for tests).
     */
    public static function reset(): void
    {
        self::$inflight = [];
    }

    /**
     * @param \Closure $body Bound call to the original method.
     */
    private static function compute(string $cache, string $key, ?int $ttl, bool $cacheNull, \Closure $body): mixed
    {
        $value = $body();

        if ($value !== null || $cacheNull) {
            self::store($cache, $key, $ttl, $value);
        }

        return $value;
    }

    private static function store(string $cache, string $key, ?int $ttl, mixed $value): void
    {
        try {
            $serialized = serialize($value);
        } catch (\Throwable $e) {
            CacheManager::store($cache)->evict($key);
            CacheManager::skipped($cache, 'the value cannot be serialized (' . $e->getMessage() . ')');

            return;
        }

        CacheManager::store($cache)->put($key, $serialized, $ttl);
    }

    private static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }
}
