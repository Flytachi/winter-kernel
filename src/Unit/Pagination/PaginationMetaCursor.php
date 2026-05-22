<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use JsonSerializable;

/**
 * Cursor-based pagination metadata (bidirectional next/prev).
 *
 * Carried by {@see PaginationResult} when produced by {@see Paginator::cursor()}.
 * Unlike {@see PaginationMeta}, no `total` field — cursor pagination intentionally
 * skips the COUNT query to stay constant-cost regardless of set size.
 *
 * **Boolean presence is encoded by null** — a cursor field is `null` if and
 * only if that navigation direction is unavailable. No redundant `hasNext` /
 * `hasPrev` flags: single source of truth, smaller payload, simpler clients.
 *
 * ```js
 * if (meta.cursorNext) showNextButton(meta.cursorNext);
 * if (meta.cursorPrev) showPrevButton(meta.cursorPrev);
 * ```
 *
 * A cursor is an opaque `base64(json({...}))` snapshot of the position in the
 * ordered set, with navigation direction encoded inside the token. Clients
 * echo a single cursor value back via `?cursor=…` to navigate; the encoding
 * format is an implementation detail and must not be parsed by clients.
 *
 * JSON shape:
 * ```
 * {
 *   "size":       20,
 *   "cursorPrev": null,
 *   "cursorNext": "eyJzIjoiYTNmMmIxYzgiLCJ2IjpbMTIzXSwiZCI6ImYifQ=="
 * }
 * ```
 */
final readonly class PaginationMetaCursor implements JsonSerializable
{
    /**
     * @param int $size Page size that was requested. `>= 1`.
     * @param string|null $cursorPrev Cursor for navigating backward (to the previous page).
     *                                `null` when there is no previous page (first page or empty result).
     * @param string|null $cursorNext Cursor for navigating forward (to the next page).
     *                                `null` when there is no next page (last page or empty result).
     */
    public function __construct(
        public int $size,
        public ?string $cursorPrev,
        public ?string $cursorNext
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'size'       => $this->size,
            'cursorPrev' => $this->cursorPrev,
            'cursorNext' => $this->cursorNext,
        ];
    }
}
