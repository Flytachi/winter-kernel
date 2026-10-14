<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Store;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;

/**
 * Entries kept in a PHP array of the current worker, dropped least recently used
 * first.
 *
 * A PHP array remembers insertion order, so LRU needs no second structure: a hit
 * moves its entry to the end, and the first entry is the one to drop. The move
 * costs about 18 ns per hit, against the ~170 ns the hit already pays to
 * unserialize its copy.
 *
 * @internal
 */
final class MemoryStore implements CacheStore
{
    /** @var array<string, array{string, int}> Key mapped to the serialized value and its expiry (0 = never). */
    private array $entries = [];

    public function __construct(private readonly CacheDefinition $definition)
    {
    }

    public function get(string $key): ?string
    {
        if (!isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];
        unset($this->entries[$key]);

        if ($entry[1] !== 0 && $entry[1] <= time()) {
            return null;
        }

        $this->entries[$key] = $entry;

        return $entry[0];
    }

    public function put(string $key, string $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->definition->ttl ?? 0;

        unset($this->entries[$key]);
        $this->entries[$key] = [$value, $ttl > 0 ? time() + $ttl : 0];

        if (count($this->entries) > $this->definition->size) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        return true;
    }

    public function evict(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
