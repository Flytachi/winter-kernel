<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use InvalidArgumentException;

/**
 * Describes the ordering column(s) used by {@see Paginator::cursor()}.
 *
 * A `CursorKey` declares:
 * - `$column` — the SQL expression used in `ORDER BY` and the cursor `WHERE`,
 * - `$direction` — sort direction ({@see Sort::Asc} / {@see Sort::Desc}),
 * - `$tiebreaker` — optional recursive secondary key (used to break ties on
 *   the primary column; critical for stable pagination across duplicates),
 * - `$alias` — optional override for the hydrated-entity property name. When
 *   `null`, the paginator strips any table prefix from `$column` (`'p.name'`
 *   → `'name'`) — works when the SELECT emits the column without an `AS`
 *   alias. Set explicitly when the SELECT uses an alias different from the
 *   bare column name, e.g. `SELECT p.name AS product_name`.
 *
 * Two factory shapes are supported:
 *
 * ```
 * // Direct construction — single column or nested tiebreakers:
 * new CursorKey('id', Sort::Desc);
 *
 * new CursorKey('created_at', Sort::Desc,
 *     tiebreaker: new CursorKey('id', Sort::Desc));
 *
 * // Flat composition — better for runtime-built keys (dynamic ORDER BY
 * // from a request, MUI grid sort spec, etc.):
 * CursorKey::compose(
 *     new CursorKey('p.name', Sort::Asc, alias: 'product_name'),
 *     new CursorKey('c.name', Sort::Asc, alias: 'category_name'),
 *     new CursorKey('p.id',   Sort::Asc),
 * );
 * ```
 *
 * The signature ({@see signature()}) is embedded into cursor tokens; tokens
 * issued under a different `(column, direction)` shape are rejected on decode.
 * `alias` does **not** affect the signature — it is a runtime concern (how to
 * read a value from a hydrated row), not a contract about the underlying data.
 */
final readonly class CursorKey
{
    /**
     * @param string $column SQL column or expression (e.g. `'id'`, `'p.name'`).
     *                       Used verbatim in `ORDER BY` and `WHERE` clauses.
     * @param Sort $direction Sort direction. Defaults to {@see Sort::Desc}.
     * @param CursorKey|null $tiebreaker Optional recursive tiebreaker.
     * @param string|null $alias Hydrated entity property name. When `null`,
     *                           the paginator strips the table prefix from
     *                           `$column` (`'p.name'` → `'name'`) and uses
     *                           that. Provide explicitly when the SELECT
     *                           uses an `AS` alias different from the bare
     *                           column name.
     */
    public function __construct(
        public string $column,
        public Sort $direction = Sort::Desc,
        public ?CursorKey $tiebreaker = null,
        public ?string $alias = null,
    ) {
    }

    /**
     * Builds a composite `CursorKey` from a flat list of leaf keys.
     *
     * The first key becomes the primary sort, each subsequent key becomes
     * the next tiebreaker level. Ideal for dynamic construction from runtime
     * sort specs (e.g. a request's `sortModel`):
     *
     * ```
     * $leaves = [];
     * foreach ($request->sort as $s) {
     *     $leaves[] = new CursorKey($fieldMap[$s->field], Sort::from($s->dir));
     * }
     * $leaves[] = new CursorKey('id', Sort::Asc); // always-stable tiebreaker
     * $key = CursorKey::compose(...$leaves);
     * ```
     *
     * Passed keys must be leaves — passing a key that already has a
     * `$tiebreaker` is rejected to avoid ambiguity about the final shape.
     *
     * @throws InvalidArgumentException When no keys are passed, or any key has
     *                                  a non-null `$tiebreaker`.
     */
    public static function compose(self ...$keys): self
    {
        if (empty($keys)) {
            throw new InvalidArgumentException('CursorKey::compose() requires at least one key.');
        }
        $result = null;
        foreach (array_reverse($keys) as $key) {
            if ($key->tiebreaker !== null) {
                throw new InvalidArgumentException(
                    'CursorKey::compose() expects leaf keys without tiebreakers; '
                    . "got a nested key for column '$key->column'."
                );
            }
            $result = new self($key->column, $key->direction, tiebreaker: $result, alias: $key->alias);
        }
        return $result;
    }

    /**
     * Returns the effective property name for reading this column's value
     * from a hydrated entity.
     *
     * Priority:
     * 1. `$alias` when explicitly set.
     * 2. Auto-strip table prefix: `'p.name'` → `'name'`.
     * 3. `$column` verbatim when no prefix is present.
     */
    public function effectiveAlias(): string
    {
        if ($this->alias !== null) {
            return $this->alias;
        }
        $pos = strrpos($this->column, '.');
        return $pos === false ? $this->column : substr($this->column, $pos + 1);
    }

    /**
     * Flattens the (possibly nested) key into an ordered list of
     * `[column, direction, alias]` triples — used by the paginator to build
     * the `ORDER BY` clause, the cursor `WHERE` predicate, and to extract
     * cursor values from hydrated rows.
     *
     * @return list<array{0: string, 1: Sort, 2: string}>
     */
    public function flatten(): array
    {
        $out = [[$this->column, $this->direction, $this->effectiveAlias()]];
        if ($this->tiebreaker !== null) {
            foreach ($this->tiebreaker->flatten() as $triple) {
                $out[] = $triple;
            }
        }
        return $out;
    }

    /**
     * Stable 8-character signature of the key shape (column + direction,
     * recursive through tiebreakers). `alias` is deliberately not part of
     * the signature — it is a runtime concern, not a contract about the
     * underlying data.
     *
     * Embedded into cursor tokens by {@see CursorToken::encode()} so that
     * tokens issued under a different key shape are rejected on decode
     * rather than silently returning incorrect results.
     */
    public function signature(): string
    {
        $repr = $this->column . '|' . $this->direction->value;
        if ($this->tiebreaker !== null) {
            $repr .= '>' . $this->tiebreaker->signature();
        }
        return substr(hash('xxh3', $repr), 0, 8);
    }
}
