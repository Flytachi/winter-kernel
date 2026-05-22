<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

/**
 * Sort direction for ordered queries — used by {@see CursorKey} and forwarded
 * to repository `ORDER BY` clauses.
 *
 * The string value matches the literal SQL keyword, so it can be interpolated
 * directly: `"ORDER BY id {$direction->value}"`.
 */
enum Sort: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';

    /**
     * Returns the opposite direction.
     *
     * Used by {@see Paginator::cursor()} when navigating backward — the
     * effective `ORDER BY` is inverted so that "the N rows immediately
     * before the cursor" are selected (not "the highest N rows above it").
     */
    public function invert(): self
    {
        return $this === self::Asc ? self::Desc : self::Asc;
    }
}
