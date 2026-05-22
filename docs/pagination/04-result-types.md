# Result Types

Two parallel container families exist — pick by API shape:

| Container | Meta | Returned by | Style |
|-----------|------|-------------|-------|
| `PaginationResult<TMeta, TItem>` | `PaginationMeta` or `PaginationMetaCursor` | `Paginator::repo/array/cursor` | offset-centric |
| `WrapResult<TItem>` | `WrapMeta` | `Wrapper::paginator()` | page-centric |

All four classes are `final readonly` and implement `JsonSerializable` —
`json_encode($result)` produces the API payload directly.

---

## PaginationResult

```php
final readonly class PaginationResult implements JsonSerializable
{
    public function __construct(
        public PaginationMeta|PaginationMetaCursor $meta,
        public array $data,
    ) {}
}
```

Generic over `<TMeta, TItem>`:

```php
/**
 * @template TMeta of PaginationMeta|PaginationMetaCursor
 * @template TItem
 */
```

Static analyzers (PHPStan / Psalm) infer both type parameters from the factory
call — IDE completion on `$result->meta` and `$result->data[*]` works without
runtime cost.

### JSON shape

```json
{
  "meta": { ... },
  "data": [ ... ]
}
```

Implements `JsonSerializable` — `json_encode($result)` produces the API
payload directly.

```php
return new JsonResponse($result);   // works out of the box
```

---

## PaginationMeta — offset-based

```php
final readonly class PaginationMeta implements JsonSerializable
{
    public function __construct(
        public int $offset,
        public int $size,
        public int $total,
    ) {}
}
```

Returned by `Paginator::repo()` and `Paginator::array()`.

| Field | Description |
|-------|-------------|
| `offset` | Current page offset. `>= 0`. |
| `size` | Page size requested. `>= 1`. |
| `total` | Total items in the underlying set (after filters, before pagination). |

### JSON shape

```json
{ "offset": 40, "size": 20, "total": 156 }
```

### Derived values

`PaginationMeta` intentionally **does not store** derived values — compute
them at the call site to keep the meta object minimal:

```php
$page       = (int) floor($meta->offset / $meta->size) + 1;
$hasNext    = $meta->offset + $meta->size < $meta->total;
$totalPages = (int) ceil($meta->total / $meta->size);
```

---

## PaginationMetaCursor — cursor-based

```php
final readonly class PaginationMetaCursor implements JsonSerializable
{
    public function __construct(
        public int $size,
        public ?string $cursorPrev,
        public ?string $cursorNext,
    ) {}
}
```

Returned by `Paginator::cursor()`. **No `hasNext` / `hasPrev` flags** — boolean
presence is encoded by `null` vs non-null on the cursor fields themselves.
Single source of truth, smaller payload, simpler client code.

| Field | Description |
|-------|-------------|
| `size` | Page size requested. `>= 1`. |
| `cursorPrev` | Opaque cursor for navigating to the previous page. `null` when there is no previous page (first page or empty result). Tagged with `direction=backward` inside the token. |
| `cursorNext` | Opaque cursor for navigating to the next page. `null` when there is no next page (last page or empty result). Tagged with `direction=forward`. |

### Client logic

```js
if (meta.cursorPrev) showPrevButton(meta.cursorPrev);
if (meta.cursorNext) showNextButton(meta.cursorNext);
```

### JSON shape

```json
{
  "size": 20,
  "cursorPrev": null,
  "cursorNext": "eyJzIjoiYTNmMmIxYzgiLCJ2IjpbOTgxXSwiZCI6ImYifQ=="
}
```

JSON keys are **camelCase** matching PHP property names — `jsonSerialize()`
is a direct field map.

### Cursor encoding

A cursor is `base64(json({"s": "<sig>", "v": [...values], "d": "<f|b>"}))` —
opaque to clients. Clients echo it back unchanged via a single `$cursor`
parameter; the server reads navigation direction from the embedded `d` field.
See [03-cursor.md](03-cursor.md).

---

## Example — full round trip

```php
use Flytachi\Winter\K2\Unit\Pagination\Paginator;

// Controller
public function index(Request $req): JsonResponse
{
    $result = Paginator::repo(
        ProductRepository::instance()
            ->where(Qb::eq('available', true))
            ->orderBy('name ASC'),
        size: (int) $req->query('size', 20),
        offset: (int) $req->query('offset', 0),
        mapper: fn (ProductEntity $p) => ProductResource::from($p),
    );

    return new JsonResponse($result);
}
```

Response:

```json
{
  "meta": { "offset": 0, "size": 20, "total": 156 },
  "data": [
    { "id": 1, "name": "...", "price": "9.99" },
    ...
  ]
}
```

Client computes navigation from the meta alone — no extra fields needed in
the response envelope.

---

## WrapResult — page-centric envelope

```php
final readonly class WrapResult implements JsonSerializable
{
    public function __construct(
        public WrapMeta $meta,
        public array $data,
    ) {}
}
```

Generic over `<TItem>`:

```php
/**
 * @template TItem
 */
```

Returned by {@see \Flytachi\Winter\K2\Unit\Wrapper::paginator()}. Pairs with
{@see WrapMeta}. See [05-wrapper.md](05-wrapper.md) for the full Wrapper
contract.

### JSON shape

```json
{
  "meta": { ... },
  "data": [ ... ]
}
```

Identical envelope keys to `PaginationResult` (`meta` + `data`) — only the
inner `meta` shape differs.

---

## WrapMeta — page-centric

```php
final readonly class WrapMeta implements JsonSerializable
{
    public function __construct(
        public int $current,
        public int $size,
        public int $total,
        public int $pages,
        public ?int $previous,
        public ?int $next,
    ) {}
}
```

Returned by `Wrapper::paginator()` inside `WrapResult`.

| Field | Description |
|-------|-------------|
| `current` | Current page (1-based). `>= 1`. |
| `size` | Page size requested. `>= 1`. |
| `total` | Total items in the underlying set. |
| `pages` | Total number of pages. `0` when `total = 0`. |
| `previous` | Previous page number, or `null` on the first page. |
| `next` | Next page number, or `null` on the last page. |

### JSON shape

```json
{
  "current": 3,
  "size": 20,
  "total": 156,
  "pages": 8,
  "previous": 2,
  "next": 4
}
```

`previous`/`next` use `null` (not `0`) for absent pages — TypeScript-friendly
`number | null` typing.

---

## Choosing between meta types

| Need | Use |
|------|-----|
| Minimal `{offset, size, total}` for API consumers | `PaginationMeta` |
| Numbered-page UI with prev/next links | `WrapMeta` |
| Cursor-based feed / infinite scroll | `PaginationMetaCursor` |

Both `PaginationMeta` and `WrapMeta` are derivable from each other client-side
— pick the one whose shape matches your client's expectations.

---

## Sort enum

```php
enum Sort: string
{
    case Asc  = 'ASC';
    case Desc = 'DESC';

    public function invert(): self;   // Asc ↔ Desc
}
```

Used by {@see CursorKey} and by the cursor paginator's internal ORDER BY
generation. String values match SQL keywords for direct interpolation.

---

## CursorDirection enum

```php
enum CursorDirection: string
{
    case Forward  = 'f';   // after  — fetch rows beyond the cursor
    case Backward = 'b';   // before — fetch rows ahead of the cursor
}
```

Encoded into every cursor token (the `d` field) so that
{@see Paginator::cursor()} can accept a single `$cursor` parameter and derive
navigation direction from the token itself. Single-character values keep
encoded tokens compact.

Internal — clients should not parse cursor tokens.

---

## CursorKey

```php
final readonly class CursorKey
{
    public function __construct(
        public string $column,
        public Sort $direction = Sort::Desc,
        public ?CursorKey $tiebreaker = null,
        public ?string $alias = null,
    ) {}

    /** Flat composition factory — accepts leaf keys only. */
    public static function compose(self ...$keys): self;

    /** Effective hydrated-entity property — explicit `alias` or auto-strip of `column`. */
    public function effectiveAlias(): string;

    /** @return list<array{0: string, 1: Sort, 2: string}>  [column, direction, alias] */
    public function flatten(): array;

    /** 8-char xxh3 hash of the recursive (column, direction) shape — alias excluded. */
    public function signature(): string;
}
```

Describes the ordering used by {@see Paginator::cursor()}. Recursive
`tiebreaker` (or flat `compose()`) supports composite cursors of any depth
for stable pagination across ties.

`column` is the SQL expression for `ORDER BY` / `WHERE`. `alias` is the PHP
property on the hydrated entity used to read cursor values back out — set
explicitly when SELECT uses `AS` (e.g. `SELECT p.name AS product_name`),
otherwise auto-derived by stripping the table prefix from `column`.

See [03-cursor.md](03-cursor.md) for the full cursor contract and examples.

### Examples

```php
// Single column:
new CursorKey('id', Sort::Desc);

// With explicit alias for AS-aliased SELECT:
new CursorKey('p.name', Sort::Asc, alias: 'product_name');

// Composite — primary by created_at, tiebreak by id (recursive form):
new CursorKey('created_at', Sort::Desc, tiebreaker: new CursorKey('id', Sort::Desc));

// Composite — flat form via compose() (better for 3+ levels or dynamic builds):
CursorKey::compose(
    new CursorKey('p.name', Sort::Asc, alias: 'product_name'),
    new CursorKey('c.name', Sort::Asc, alias: 'category_name'),
    new CursorKey('p.id',   Sort::Asc),
);
```

### Signature

The 8-char `xxh3` hash of `column|direction[>tiebreaker_sig]` is embedded
into cursor tokens. Tokens issued under a different `(column, direction)`
shape (e.g. after a redeploy that added a tiebreaker, changed sort direction,
or swapped column) are rejected on decode via {@see InvalidCursorException}.

`alias` is **not** part of the signature — changing only the alias does not
invalidate existing client cursors.

---

## Notes

- All four classes (`PaginationResult`, `WrapResult`, `PaginationMeta`,
  `WrapMeta`, `PaginationMetaCursor`) are `final readonly` — once constructed,
  no field can be reassigned. Pass them through map/transform pipelines as
  immutable values.
- The `data` array is `list<TItem>` — sequentially indexed from 0. The
  paginators do not preserve original array keys (use `Paginator::array()`
  with awareness of `array_slice` behavior if keys matter).
- All meta and result classes are `final` — extend by composition, not
  inheritance.
