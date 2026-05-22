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
        public ?string $beforeCursor,
        public ?string $afterCursor,
        public bool $hasNextPage,
        public bool $hasPrevPage,
    ) {}
}
```

Returned by `Paginator::cursor()`.

| Field | Description |
|-------|-------------|
| `size` | Page size requested. `>= 1`. |
| `beforeCursor` | Opaque cursor for navigating backward. `null` when no earlier page exists. |
| `afterCursor` | Opaque cursor for navigating forward. `null` when no later page exists. |
| `hasNextPage` | `true` when another page exists after the current one. |
| `hasPrevPage` | `true` when another page exists before the current one. |

### JSON shape

```json
{
  "size": 20,
  "has_next_page": true,
  "has_previous_page": false,
  "before_cursor": null,
  "after_cursor": "eyJpZCI6OTgxfQ=="
}
```

> The JSON field names are **snake_case** (`has_next_page`, `before_cursor`),
> while the PHP property names are **camelCase** (`hasNextPage`, `beforeCursor`).
> The class' `jsonSerialize()` performs the mapping.

### Cursor encoding

A cursor is `base64(json({...}))` — an opaque string. Clients must treat it
as a black box and echo it back unchanged. See [03-cursor.md](03-cursor.md).

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
