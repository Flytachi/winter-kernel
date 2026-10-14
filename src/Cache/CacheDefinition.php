<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

/**
 * One cache as declared in {@see Caches}.
 */
final readonly class CacheDefinition
{
    /**
     * Bytes a `Swoole\Table` row costs beyond its value column: the key, the
     * expiry column and the table's own bookkeeping. Measured, not documented.
     */
    private const int ROW_OVERHEAD = 120;

    /**
     * Extra rows a table reserves for hash collisions.
     *
     * Swoole's default of 0.2 lets a table hold anything from half to all of the rows
     * it was asked for, depending on the keys. At 0.5, with hashed keys, it held at
     * least 111% of them in every measurement — so `rows` can be promised.
     */
    public const float CONFLICT_PROPORTION = 0.5;

    /**
     * @param string $name Name the attributes refer to.
     * @param CacheType $type Where entries are kept.
     * @param int $size Rows of a table, or entries of a memory cache.
     * @param int|null $valueSize Largest serialized value a table row holds, in bytes; null for memory.
     * @param int|null $ttl Default lifetime in seconds; null or 0 never expires.
     */
    public function __construct(
        public string $name,
        public CacheType $type,
        public int $size,
        public ?int $valueSize,
        public ?int $ttl,
    ) {
    }

    /**
     * Shared memory a table reserves when it is created, in bytes; 0 for memory.
     *
     * An estimate: `Swoole\Table::$memorySize` reports less than the process
     * actually takes, so it is computed from the rounding Swoole applies — rows up
     * to a power of two, plus the collision share.
     */
    public function reservedBytes(): int
    {
        if ($this->type !== CacheType::Table) {
            return 0;
        }

        $rows = 1 << (int) ceil(log(max(1, $this->size), 2));

        return (int) ($rows * (1 + self::CONFLICT_PROPORTION) * ((int) $this->valueSize + self::ROW_OVERHEAD));
    }
}
