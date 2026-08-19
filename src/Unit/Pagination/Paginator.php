<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Pagination;

use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\Ppa\Entity\RepositoryViewInterface;
use LogicException;
use ValueError;

/**
 * Final stateless pagination service — three strategies for paginating data.
 *
 * - {@see self::repo()}   — offset over a `RepositoryViewInterface`. Issues
 *                            a `COUNT` for the total. For numbered-page UIs
 *                            and admin lists.
 * - {@see self::array()}  — offset over an in-memory array. Pure function,
 *                            no SQL. For pre-loaded collections.
 * - {@see self::cursor()} — bidirectional keyset (seek-method) cursor. No
 *                            `COUNT`, constant cost regardless of set size,
 *                            stable across concurrent writes. For feeds,
 *                            chat history, audit logs, infinite scroll —
 *                            see {@see CursorKey}, {@see CursorToken}.
 *
 * All three return a {@see PaginationResult} — `readonly` + `JsonSerializable`
 * — with `meta` and `data` fields ready to ship as an API payload.
 *
 * Stateless. Safe for concurrent calls in Swoole coroutines.
 *
 * @version 6.0
 * @author Flytachi
 */
final class Paginator
{
    /**
     * Offset-based repository pagination with total row count (COUNT).
     *
     * Issues two queries:
     *  1. `SELECT ... LIMIT $size OFFSET $offset` — current page rows.
     *  2. `SELECT COUNT(*) FROM (...)` — without `ORDER BY / LIMIT / OFFSET / FOR`
     *     (see {@see RepositoryInterface::buildSql()} with `ignoreParts`).
     *
     * Mutates the repository — calls `$repo->limit($size, $offset)`. If the
     * caller plans to reuse `$repo` after pagination, clone it beforehand.
     *
     * Example:
     * ```
     * $result = Paginator::repo(
     *     $repo,
     *     size: 20,
     *     offset: 40,
     *     mapper: fn ($row) => ProductResource::from($row),
     * );
     * ```
     *
     * @template TEntity of object
     * @template TOverride of object
     *
     * @param RepositoryViewInterface<TEntity> $repo Source repository with `WHERE / ORDER BY / ...` already applied.
     * @param int $size Page size. Must be `>= 1`.
     * @param int $offset Offset from the start of the result set. Defaults to `0` (first page).
     * @param class-string<TOverride>|null $entityClassName Class for row hydration; forwarded to
     *                                                      {@see RepositoryViewInterface::findAll()}.
     *                                                      `null` falls back to the repo's default entity.
     * @param callable|null $mapper Optional per-item transformer (array_map-style).
     *                              Applied to the fetched rows before result assembly.
     *                              Signature: `fn (TEntity $item): mixed`. When provided,
     *                              cast the resulting `$data` to your mapper's return type.
     * @return ($entityClassName is null
     *             ? PaginationResult<PaginationMeta, TEntity>
     *             : PaginationResult<PaginationMeta, TOverride>) Container with {@see PaginationMeta} and page data.
     * @throws ValueError When `$size < 1`.
     */
    public static function repo(
        RepositoryViewInterface $repo,
        int $size,
        int $offset = 0,
        ?string $entityClassName = null,
        ?callable $mapper = null,
    ): PaginationResult {
        if ($size <= 0) {
            throw new ValueError("Size must be a positive integer (>= 1), got: $size.");
        }

        $repo->limit($size, $offset);
        $total = self::calculateTotal($repo);
        $data = $repo->findAll($entityClassName);

        return new PaginationResult(
            meta: new PaginationMeta(
                offset: $offset,
                size: $size,
                total: $total,
            ),
            data: $mapper === null ? $data : array_map($mapper, $data),
        );
    }

    /**
     * Offset-based pagination of an in-memory array.
     *
     * Counterpart to {@see self::repo()} for plain arrays — no SQL, no COUNT.
     * The full collection must be available up front; `total` is `count($items)`.
     *
     * Example:
     * ```
     * $page = Paginator::array(
     *     items: $rows,
     *     size: 50,
     *     offset: 100,
     *     mapper: fn ($r) => Row::from($r),
     * );
     * ```
     *
     * @template TItem
     *
     * @param array<TItem> $items Full collection to paginate over.
     * @param int $size Page size. Must be `>= 1`.
     * @param int $offset Offset from the start of the array. Defaults to `0`.
     * @param callable|null $mapper Optional per-item transformer (array_map-style).
     *                              Applied to the sliced page only, not to the full input.
     *                              Signature: `fn (TItem $item): mixed`. When provided,
     *                              cast the resulting `$data` to your mapper's return type.
     * @return PaginationResult<PaginationMeta, TItem> Container with {@see PaginationMeta} and the sliced page data.
     * @throws ValueError When `$size < 1`.
     */
    public static function array(
        array $items,
        int $size,
        int $offset = 0,
        ?callable $mapper = null,
    ): PaginationResult {
        if ($size <= 0) {
            throw new ValueError("Size must be a positive integer (>= 1), got: $size.");
        }

        $data = array_slice($items, $offset, $size);

        return new PaginationResult(
            meta: new PaginationMeta(offset: $offset, size: $size, total: count($items)),
            data: $mapper === null ? $data : array_map($mapper, $data),
        );
    }

    /**
     * Bidirectional cursor-based pagination (keyset / seek method).
     *
     * Owns the `ORDER BY` clause — the input repository must **not** have
     * `orderBy()` pre-applied (any prior `ORDER BY` is overwritten). Pre-applied
     * `WHERE / JOIN / GROUP BY` is preserved; the cursor condition is added via
     * `andWhere()`.
     *
     * Cursor tokens are opaque, base64-encoded JSON envelopes carrying the
     * cursor values, a {@see CursorKey::signature()} for key-shape validation,
     * and a {@see CursorDirection} so a single `$cursor` parameter is enough —
     * the server reads navigation direction from the token itself. Tokens
     * issued under a different key shape are rejected via {@see InvalidCursorException}.
     *
     * @template TEntity of object
     * @template TOverride of object
     *
     * @param RepositoryViewInterface<TEntity> $repo Source repository (without `ORDER BY`).
     * @param int $size Page size. Must be `>= 1`.
     * @param CursorKey $key Ordering key — single column or composite with tiebreakers.
     * @param string|null $cursor Opaque cursor token from a previous response's
     *                            `cursorPrev` / `cursorNext`. `null` for the
     *                            first page. Direction is encoded in the token.
     * @param class-string<TOverride>|null $entityClassName Hydration override.
     * @param callable|null $mapper Optional per-item transformer applied to the
     *                              fetched page before assembly. Signature:
     *                              `fn (TEntity $item): mixed`.
     * @return ($entityClassName is null
     *             ? PaginationResult<PaginationMetaCursor, TEntity>
     *             : PaginationResult<PaginationMetaCursor, TOverride>)
     *
     * @throws ValueError When `$size < 1`.
     * @throws InvalidCursorException When the cursor token is malformed or its signature
     *                                does not match `$key`.
     */
    final public static function cursor(
        RepositoryViewInterface $repo,
        int $size,
        CursorKey $key,
        ?string $cursor = null,
        ?string $entityClassName = null,
        ?callable $mapper = null,
    ): PaginationResult {
        if ($size <= 0) {
            throw new ValueError("Size must be a positive integer (>= 1), got: $size.");
        }

        $triples   = $key->flatten();
        $signature = $key->signature();

        $backward = false;
        if ($cursor !== null) {
            [$values, $direction] = CursorToken::decode($cursor, $signature);
            if (count($values) !== count($triples)) {
                throw new InvalidCursorException(
                    'Cursor value count does not match key shape (expected '
                    . count($triples) . ', got ' . count($values) . ').'
                );
            }
            $backward = $direction === CursorDirection::Backward;
            $repo->andWhere(self::buildCursorWhere($triples, $values, forward: !$backward));
        }

        // Paginator owns ORDER BY — invert direction when navigating backward,
        // so the DB returns the rows immediately adjacent to the cursor (not
        // the extreme N rows on the wrong side).
        $repo->orderBy(self::buildCursorOrderBy($triples, invert: $backward));

        // Fetch +1 to detect whether more pages exist in the navigation direction.
        $repo->limit($size + 1);
        $list  = $repo->findAll($entityClassName);
        $extra = count($list) > $size;
        if ($extra) {
            array_pop($list); // drop the probe row
        }

        // Restore visible order — backward inverted ORDER BY for selection only.
        if ($backward) {
            $list = array_reverse($list);
        }

        // Cursor emission rules — null iff navigation in that direction is unavailable.
        //
        //                       cursorPrev (backward)       cursorNext (forward)
        //   First page  → null                              encode(last)  if $extra else null
        //   Forward     → encode(first)                     encode(last)  if $extra else null
        //   Backward    → encode(first) if $extra else null encode(last)
        //   Empty page  → null

        $isFirstPage = ($cursor === null);

        [$cursorPrev, $cursorNext] = self::computeCursorTokens(
            list: $list,
            triples: $triples,
            signature: $signature,
            isFirstPage: $isFirstPage,
            backward: $backward,
            hasMoreInDirection: $extra,
        );

        return new PaginationResult(
            meta: new PaginationMetaCursor(
                size: $size,
                cursorPrev: $cursorPrev,
                cursorNext: $cursorNext,
            ),
            data: $mapper === null ? $list : array_map($mapper, $list),
        );
    }

    /**
     * Builds the `ORDER BY` clause string from a flattened {@see CursorKey}.
     *
     * When `$invert` is `true`, each column's direction is flipped — used by
     * cursor pagination for backward navigation so the DB selects the rows
     * immediately adjacent to the cursor.
     *
     * @param list<array{0: string, 1: Sort, 2: string}> $triples
     */
    private static function buildCursorOrderBy(array $triples, bool $invert): string
    {
        $parts = [];
        foreach ($triples as [$col, $dir, $_alias]) {
            $effective = $invert ? $dir->invert() : $dir;
            $parts[] = $col . ' ' . $effective->value;
        }
        return implode(', ', $parts);
    }

    /**
     * Builds the cursor `WHERE` predicate (keyset/seek-method composite condition).
     *
     * For triples `[(c1, d1, …), (c2, d2, …), ...]` and values `[v1, v2, ...]`, generates:
     * ```
     * OR(
     *   c1 <op1> v1,
     *   AND(c1 = v1, c2 <op2> v2),
     *   AND(c1 = v1, c2 = v2, c3 <op3> v3),
     *   ...
     * )
     * ```
     * where `<opi>` is `<` for `Sort::Desc` (or `>` when navigating backward),
     * `>` for `Sort::Asc` (or `<` when navigating backward).
     *
     * @param list<array{0: string, 1: Sort, 2: string}> $triples
     * @param list<mixed> $values
     */
    private static function buildCursorWhere(array $triples, array $values, bool $forward): Qb
    {
        $orClauses = [];
        foreach ($triples as $i => [$col, $dir, $_alias]) {
            $useLess = $forward ? $dir === Sort::Desc : $dir === Sort::Asc;

            $andParts = [];
            for ($j = 0; $j < $i; $j++) {
                $andParts[] = Qb::eq($triples[$j][0], $values[$j]);
            }
            $andParts[] = $useLess
                ? Qb::lt($col, $values[$i])
                : Qb::gt($col, $values[$i]);

            $orClauses[] = count($andParts) === 1 ? $andParts[0] : Qb::and(...$andParts);
        }

        return count($orClauses) === 1 ? $orClauses[0] : Qb::or(...$orClauses);
    }

    /**
     * Computes the prev/next cursor tokens for the current page.
     *
     * Each cursor is `null` when navigation in that direction is unavailable —
     * boolean presence is encoded by null vs. non-null, so clients do not need
     * to separate `has*` flags.
     *
     * `cursorPrev` is `null` when:
     *   - the page is empty, OR
     *   - this is the first page (no `$cursor` was provided), OR
     *   - we navigated backward and ran out of rows (no probe row above).
     *
     * `cursorNext` is `null` when:
     *   - the page is empty, OR
     *   - we navigated forward (or first-load) and ran out of rows
     *     (no probe row below).
     *
     * @param list<object> $list
     * @param list<array{0: string, 1: Sort, 2: string}> $triples
     * @param bool $hasMoreInDirection Whether the probe row was found in the
     *                                 navigation direction (forward when
     *                                 first-load or `!$backward`; backward
     *                                 when `$backward`).
     * @return array{0: string|null, 1: string|null} `[cursorPrev, cursorNext]`
     */
    private static function computeCursorTokens(
        array $list,
        array $triples,
        string $signature,
        bool $isFirstPage,
        bool $backward,
        bool $hasMoreInDirection,
    ): array {
        if (empty($list)) {
            return [null, null];
        }

        $cursorPrev = null;
        $cursorNext = null;

        // Forward direction availability — when there is a next page:
        //   - first-load / forward: $hasMoreInDirection signals it
        //   - backward: we came from a forward page, so a next page always exists
        $forwardAvailable = $backward || $hasMoreInDirection;
        if ($forwardAvailable) {
            $cursorNext = CursorToken::encode(
                $signature,
                self::extractRowValues(end($list), $triples),
                CursorDirection::Forward,
            );
        }

        // Backward direction availability — when there is a previous page:
        //   - first-load: never (no previous page)
        //   - forward: we came from somewhere, so a previous page always exists
        //   - backward: $hasMoreInDirection signals it
        $backwardAvailable = !$isFirstPage && (!$backward || $hasMoreInDirection);
        if ($backwardAvailable) {
            $cursorPrev = CursorToken::encode(
                $signature,
                self::extractRowValues($list[0], $triples),
                CursorDirection::Backward,
            );
        }

        return [$cursorPrev, $cursorNext];
    }

    /**
     * Extracts cursor values from a hydrated row using the alias map.
     *
     * @param list<array{0: string, 1: Sort, 2: string}> $triples
     * @return list<mixed>
     * @throws LogicException When the row is missing one of the cursor aliases —
     *                        usually a SELECT/alias mismatch.
     */
    private static function extractRowValues(object $row, array $triples): array
    {
        $values = [];
        foreach ($triples as [$col, $_dir, $alias]) {
            if (!property_exists($row, $alias)) {
                throw new LogicException(
                    "Cursor alias '$alias' (for column '$col') is not a property of " . $row::class
                    . ' — make sure the SELECT exposes this column with the expected alias '
                    . '(or pass an explicit `alias:` to CursorKey).'
                );
            }
            $values[] = $row->{$alias};
        }
        return $values;
    }

    private static function calculateTotal(RepositoryInterface $repo): int
    {
        $sql = $repo->buildSql(ignoreParts: ['order', 'limit', 'offset', 'for']);
        $countSql = 'SELECT COUNT(*) FROM (' . $sql . ') AS tmp';

        $stmt = new CDOStatement($repo->db()->prepare($countSql));
        if ($repo->getSql('binds')) {
            $method = method_exists($stmt, 'bindTypedValue') ? 'bindTypedValue' : 'bindValue';
            foreach ($repo->getSql('binds') as $bind) {
                $stmt->{$method}($bind->getName(), $bind->getValue());
            }
        }
        $stmt->getStmt()->execute();

        return (int) $stmt->getStmt()->fetchColumn();
    }
}
