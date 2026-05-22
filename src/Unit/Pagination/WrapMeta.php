<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use JsonSerializable;

/**
 * Page-centric pagination metadata.
 *
 * Carried by {@see WrapResult} when produced by
 * {@see \Flytachi\Winter\K2\Unit\Wrapper::paginator()}. Unlike
 * {@see PaginationMeta} (which is offset-centric), `WrapMeta` exposes the
 * page-oriented fields a classical numbered-page UI expects — `current`,
 * `pages`, plus `previous` / `next` for prev/next links.
 *
 * `previous` and `next` are `null` (not `0`) when no such page exists,
 * making the shape TypeScript-friendly (`number | null`).
 *
 * JSON shape:
 * ```
 * {
 *   "current":  3,
 *   "size":     20,
 *   "total":    156,
 *   "pages":    8,
 *   "previous": 2,
 *   "next":     4
 * }
 * ```
 */
final readonly class WrapMeta implements JsonSerializable
{
    /**
     * @param int $current Current page number (1-based). `>= 1`.
     * @param int $size Page size that was requested. `>= 1`.
     * @param int $total Total number of items in the underlying set.
     * @param int $pages Total number of pages. `0` when `$total === 0`.
     * @param int|null $previous Previous page number, or `null` on the first page.
     * @param int|null $next Next page number, or `null` on the last page.
     */
    public function __construct(
        public int $current,
        public int $size,
        public int $total,
        public int $pages,
        public ?int $previous,
        public ?int $next,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'current'  => $this->current,
            'size'     => $this->size,
            'total'    => $this->total,
            'pages'    => $this->pages,
            'previous' => $this->previous,
            'next'     => $this->next,
        ];
    }
}
