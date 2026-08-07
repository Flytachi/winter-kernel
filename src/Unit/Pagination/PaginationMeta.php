<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Pagination;

use JsonSerializable;

/**
 * Offset-based pagination metadata.
 *
 * Carried by {@see PaginationResult} when produced by {@see Paginator::repo()}
 * or {@see Paginator::array()}.
 *
 * JSON shape:
 * ```
 * {"offset": 0, "size": 20, "total": 156}
 * ```
 *
 * Derived values are intentionally not stored — callers compute them on demand:
 * ```
 * $page       = (int) floor($meta->offset / $meta->size) + 1;
 * $hasNext    = $meta->offset + $meta->size < $meta->total;
 * $totalPages = (int) ceil($meta->total / $meta->size);
 * ```
 */
final readonly class PaginationMeta implements JsonSerializable
{
    /**
     * @param int $offset Current page offset from the start of the set. `>= 0`.
     * @param int $size Page size that was requested. `>= 1`.
     * @param int $total Total number of items in the underlying set.
     */
    public function __construct(
        public int $offset,
        public int $size,
        public int $total,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'offset' => $this->offset,
            'size'   => $this->size,
            'total'  => $this->total,
        ];
    }
}
