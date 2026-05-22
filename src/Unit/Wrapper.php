<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit;

use Flytachi\Winter\K2\Ppa\Entity\RepositoryViewInterface;
use Flytachi\Winter\K2\Unit\Pagination\Paginator;
use Flytachi\Winter\K2\Unit\Pagination\WrapMeta;
use Flytachi\Winter\K2\Unit\Pagination\WrapResult;
use ValueError;

/**
 * Class Wrapper
 *
 * Thin wrapper around {@see Paginator} that produces a page-centric response
 * shape (`current`, `pages`, `previous`, `next`) suited for traditional
 * numbered-page UIs. New code that does not need page-based navigation should
 * prefer {@see Paginator::repo()} / {@see Paginator::array()} directly —
 * they return a typed `PaginationResult` with the offset-centric
 * `PaginationMeta` and native `JsonSerializable` support.
 *
 * Stateless. Safe for concurrent calls in Swoole.
 *
 * @version 6.0
 * @author Flytachi
 */
final class Wrapper
{
    /**
     * Paginate a repository query or an in-memory array.
     *
     * Delegates to {@see Paginator::repo()} (for repositories) or
     * {@see Paginator::array()} (for arrays), then re-shapes the result into
     * a typed page-centric {@see WrapResult} with {@see WrapMeta}.
     *
     * Meta is page-centric (`current`, `pages`, `previous`, `next`) — that's
     * the difference from the offset-centric {@see PaginationMeta} which
     * exposes `{offset, size, total}`. New code without page-numbered UI
     * requirements should prefer `Paginator` directly.
     *
     * @template TItem
     *
     * @param array<TItem>|RepositoryViewInterface<TItem> $repo Source — repository or in-memory list.
     * @param int $limit Page size. Must be `>= 1`.
     * @param int $page 1-based page number. Defaults to `1` (first page).
     * @param class-string|null $entityClassName Hydration override for repositories
     *                                           (ignored for array input).
     * @param callable|null $mapper Optional per-item transformer applied to the
     *                              fetched page before assembly.
     *
     * @return WrapResult<TItem> Typed page-centric response (`JsonSerializable`).
     *
     * @throws ValueError When `$limit < 1`.
     */
    final public static function paginator(
        array|RepositoryViewInterface $repo,
        int $limit,
        int $page = 1,
        ?string $entityClassName = null,
        ?callable $mapper = null,
    ): WrapResult {
        $offset = $limit * ($page - 1);

        $result = is_array($repo)
            ? Paginator::array($repo, $limit, $offset, $mapper)
            : Paginator::repo($repo, $limit, $offset, $entityClassName, $mapper);

        $total = $result->meta->total;
        $pages = $total > 0 ? (int) ceil($total / $limit) : 0;

        return new WrapResult(
            meta: new WrapMeta(
                current: $page,
                size: $limit,
                total: $total,
                pages: $pages,
                previous: $page > 1 ? $page - 1 : null,
                next: $pages > $page ? $page + 1 : null,
            ),
            data: $result->data,
        );
    }
}
