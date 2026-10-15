<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Store;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Redis\Store\RedisStore;

/**
 * Entries kept in Redis, through a winter-redis store scoped to the cache's prefix.
 *
 * The connection comes from the winter-redis pool per unit of work, so the store is
 * safe to share between coroutines and to create before workers fork. Expiry is
 * Redis's own; emptying the cache scans its prefix rather than the database.
 *
 * The store itself lets connection errors through: on the cached-call path
 * {@see \Flytachi\Winter\Kernel\Cache\CacheSupport} turns them into misses, while
 * `call cache` reports them and exits with an error.
 *
 * @internal Loaded only when winter-redis is installed.
 */
final class RedisCacheStore implements ListableStore
{
    private readonly RedisStore $redis;

    public function __construct(private readonly CacheDefinition $definition)
    {
        $this->redis = new class ((string) $definition->redisConfig, $definition->redisPrefix()) extends RedisStore {
            public function __construct(string $config, string $prefix)
            {
                $this->redisConfigClassName = $config;
                $this->prefix = $prefix;
            }
        };
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($key);

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->definition->ttl ?? 0;

        return $this->redis->set($key, $value, $ttl > 0 ? $ttl : null);
    }

    public function evict(string $key): void
    {
        $this->redis->delete($key);
    }

    public function clear(): void
    {
        $this->redis->flush();
    }

    public function count(): int
    {
        return count($this->redis->keys());
    }

    public function entries(?int $limit = null): array
    {
        $entries = [];
        foreach ($this->redis->keys() as $key) {
            if ($limit !== null && count($entries) >= $limit) {
                break;
            }

            $value = $this->redis->get($key);
            if (!is_string($value)) {
                continue;
            }

            $entries[] = ['key' => $key, 'expiresIn' => $this->redis->ttl($key), 'bytes' => strlen($value)];
        }

        return $entries;
    }

    public function location(): string
    {
        return $this->redis->key('*') . ' on ' . $this->redis->configClass();
    }
}
