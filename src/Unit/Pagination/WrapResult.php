<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Pagination;

use JsonSerializable;

/**
 * Page-centric pagination response container — meta plus page data.
 *
 * Returned by {@see \Flytachi\Winter\Kernel\Unit\Wrapper::paginator()}.
 * Implements {@see JsonSerializable} so `json_encode($result)` produces an
 * API-ready payload:
 * ```
 * {"meta": {...}, "data": [...]}
 * ```
 *
 * For offset-centric pagination (modern minimal `{offset, size, total}` shape),
 * use {@see PaginationResult} via {@see Paginator}.
 *
 * The `TItem` parameter is inferred by static analyzers from the factory call,
 * so callers get precise IDE completion on `$result->data[*]` without runtime
 * cost.
 *
 * @template TItem
 */
final readonly class WrapResult implements JsonSerializable
{
    /**
     * @param WrapMeta $meta Page-centric pagination metadata.
     * @param list<TItem> $data Current page items, after the optional `$mapper`
     *                          has been applied.
     */
    public function __construct(
        public WrapMeta $meta,
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
