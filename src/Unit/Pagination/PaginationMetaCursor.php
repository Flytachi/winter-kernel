<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use JsonSerializable;

/**
 * Cursor-based pagination metadata (bidirectional before/after).
 *
 * Carried by {@see PaginationResult} when produced by {@see Paginator::cursor()}.
 * Unlike {@see PaginationMeta}, no `total` field — cursor pagination intentionally
 * skips the COUNT query to stay constant-cost regardless of set size.
 *
 * A cursor is an opaque `base64(json({...}))` snapshot of the position in the
 * ordered set. Clients echo it back via `cursorBefore` / `cursorAfter` arguments
 * to navigate; the encoding format is an implementation detail and must not be
 * parsed by clients.
 *
 * JSON shape:
 * ```
 * {
 *   "size": 20,
 *   "has_next_page": true,
 *   "has_previous_page": false,
 *   "before_cursor": null,
 *   "after_cursor": "eyJpZCI6MTIzfQ=="
 * }
 * ```
 */
final readonly class PaginationMetaCursor implements JsonSerializable
{
    /**
     * @param int $size Page size that was requested. `>= 1`.
     * @param string|null $beforeCursor Cursor for navigating backward (toward the start).
     *                                  `null` when no earlier page exists.
     * @param string|null $afterCursor Cursor for navigating forward (toward the end).
     *                                 `null` when no later page exists.
     * @param bool $hasNextPage Whether a page exists after the current one.
     * @param bool $hasPrevPage Whether a page exists before the current one.
     */
    public function __construct(
        public int $size,
        public ?string $beforeCursor,
        public ?string $afterCursor,
        public bool $hasNextPage,
        public bool $hasPrevPage,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'size'              => $this->size,
            'has_next_page'     => $this->hasNextPage,
            'has_previous_page' => $this->hasPrevPage,
            'before_cursor'     => $this->beforeCursor,
            'after_cursor'      => $this->afterCursor,
        ];
    }
}