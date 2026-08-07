<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Pagination;

use JsonSerializable;

/**
 * Pagination response container — meta plus page data.
 *
 * Returned by all {@see Paginator} strategies. Implements {@see JsonSerializable}
 * so `json_encode($result)` produces an API-ready payload:
 * ```
 * {"meta": {...}, "data": [...]}
 * ```
 *
 * The shape of `meta` depends on the strategy used to build the result —
 * {@see PaginationMeta} for offset-based pagination ({@see Paginator::repo()},
 * {@see Paginator::array()}), {@see PaginationMetaCursor} for cursor-based
 * pagination ({@see Paginator::cursor()}).
 *
 * Both type parameters are inferred by static analyzers from the factory call,
 * so callers get precise IDE completion on `$result->meta` and `$result->data[*]`
 * without runtime cost.
 *
 * @template TMeta of PaginationMeta|PaginationMetaCursor
 * @template TItem
 */
final readonly class PaginationResult implements JsonSerializable
{
    /**
     * @param TMeta $meta Pagination metadata (offset- or cursor-based).
     * @param list<TItem> $data Current page items, after the optional `$mapper`
     *                          has been applied.
     */
    public function __construct(
        public PaginationMeta|PaginationMetaCursor $meta,
        public array $data,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'meta' => $this->meta,
            'data' => $this->data,
        ];
    }
}
