<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Store;

/**
 * Keeps the entries of one cache.
 *
 * Values cross this interface already serialized: a store only keeps strings, so
 * what a caller gets back is always a copy, never an object somebody else holds.
 *
 * @internal Reached through {@see \Flytachi\Winter\Kernel\Cache\CacheManager}.
 */
interface CacheStore
{
    /**
     * Returns the serialized value under the key, or null when there is none or it
     * has expired.
     */
    public function get(string $key): ?string;

    /**
     * Stores a serialized value.
     *
     * @param string $key Entry key.
     * @param string $value Serialized value.
     * @param int|null $ttl Lifetime in seconds; 0 never expires, null takes the cache's own.
     * @return bool False when the value was not stored; the store has already said why.
     */
    public function put(string $key, string $value, ?int $ttl = null): bool;

    /**
     * Removes one entry, if present.
     */
    public function evict(string $key): void;

    /**
     * Removes every entry.
     */
    public function clear(): void;

    /**
     * Returns the number of entries, expired ones not yet removed included.
     */
    public function count(): int;
}
