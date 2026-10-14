<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Store;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheException;
use Flytachi\Winter\Kernel\Cache\CacheManager;

/**
 * Entries kept in a `Swoole\Table`, shared by every process forked after it was
 * created.
 *
 * Three properties of the table shape this class:
 *
 * - it has a fixed size and cannot evict, so a full table refuses the write — the
 *   value is returned uncached and a warning says so, after one attempt to make
 *   room by dropping expired rows;
 * - a string column silently truncates a longer value, which would come back as a
 *   string `unserialize()` cannot read — so a value that does not fit is never
 *   written, and the old entry under its key is removed rather than left stale;
 * - keys longer than 63 bytes are refused, so keys are hashed.
 *
 * @internal
 */
final class TableStore implements CacheStore
{
    private readonly \Swoole\Table $table;

    /** When expired rows were last swept, so a full table is not walked on every miss. */
    private int $sweptAt = 0;

    /**
     * @throws CacheException If the table cannot be created.
     */
    public function __construct(private readonly CacheDefinition $definition)
    {
        if (!extension_loaded('swoole')) {
            throw CacheException::of(
                "Cache '{$definition->name}'",
                'a table cache needs ext-swoole',
                'Install it, or declare the cache with $caches->memory().'
            );
        }

        $table = new \Swoole\Table($definition->size, CacheDefinition::CONFLICT_PROPORTION);
        $table->column('value', \Swoole\Table::TYPE_STRING, (int) $definition->valueSize);
        $table->column('expires', \Swoole\Table::TYPE_INT);

        if (!$table->create()) {
            throw CacheException::of(
                "Cache '{$definition->name}'",
                'the table could not be created',
                'Lower rows or valueSize; about ' . intdiv($definition->reservedBytes(), 1048576)
                . ' MB of shared memory was requested.'
            );
        }

        $this->table = $table;
    }

    public function get(string $key): ?string
    {
        $id = self::id($key);
        $row = $this->table->get($id);
        if ($row === false) {
            return null;
        }

        if ($row['expires'] !== 0 && $row['expires'] <= time()) {
            $this->table->del($id);

            return null;
        }

        return $row['value'];
    }

    public function put(string $key, string $value, ?int $ttl = null): bool
    {
        $id = self::id($key);

        if (strlen($value) > $this->definition->valueSize) {
            $this->table->del($id);
            CacheManager::skipped($this->definition->name, sprintf(
                'a value of %d bytes does not fit valueSize %d; raise valueSize in the CacheConfigurer',
                strlen($value),
                $this->definition->valueSize,
            ));

            return false;
        }

        $ttl ??= $this->definition->ttl ?? 0;
        $row = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0];

        // The table warns on its own when it is full; that case is reported below,
        // once a minute, instead of on every write.
        if (@$this->table->set($id, $row)) {
            return true;
        }

        if ($this->sweep() > 0 && @$this->table->set($id, $row)) {
            return true;
        }

        CacheManager::skipped($this->definition->name, sprintf(
            'the table is full (%d rows declared); raise rows in the CacheConfigurer',
            $this->definition->size,
        ));

        return false;
    }

    public function evict(string $key): void
    {
        $this->table->del(self::id($key));
    }

    public function clear(): void
    {
        $ids = [];
        foreach ($this->table as $id => $row) {
            $ids[] = $id;
        }
        foreach ($ids as $id) {
            $this->table->del($id);
        }
    }

    public function count(): int
    {
        return $this->table->count();
    }

    /**
     * Drops expired rows, at most once a second per process.
     *
     * @return int Rows dropped.
     */
    private function sweep(): int
    {
        $now = time();
        if ($this->sweptAt === $now) {
            return 0;
        }
        $this->sweptAt = $now;

        $expired = [];
        foreach ($this->table as $id => $row) {
            if ($row['expires'] !== 0 && $row['expires'] <= $now) {
                $expired[] = $id;
            }
        }
        foreach ($expired as $id) {
            $this->table->del($id);
        }

        return count($expired);
    }

    private static function id(string $key): string
    {
        return hash('xxh128', $key);
    }
}
