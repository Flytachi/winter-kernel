# Wrapper::paginator() — Page-Centric Pagination

`Wrapper` is a thin façade over `Paginator` producing a **page-centric**
response — the kind classical numbered-page UIs expect: "Page 3 of 8" with
prev/next links.

Returns a typed {@see WrapResult} containing {@see WrapMeta} + `data`,
mirroring the `Paginator → PaginationResult → PaginationMeta` pattern but
with page-oriented fields instead of `{offset, size, total}`.

For offset-centric API responses, use `Paginator` directly (see
[01-offset-repo.md](01-offset-repo.md) and [02-array.md](02-array.md)).

```php
final public static function paginator(
    array|RepositoryViewInterface $repo,
    int $limit,
    int $page = 1,
    ?string $entityClassName = null,
    ?callable $mapper = null,
): WrapResult
```

---

## Parameters

| Param | Description |
|-------|-------------|
| `$repo` | Source — either a `RepositoryViewInterface` (SQL-backed) or a plain `array` (in-memory). |
| `$limit` | Page size. Must be `>= 1` — throws `ValueError` otherwise. |
| `$page` | 1-based page number. Defaults to `1`. |
| `$entityClassName` | Hydration override for repositories. Ignored for array input. |
| `$mapper` | Optional per-item transformer applied to the page before assembly. |

Returns `WrapResult<TItem>` — `readonly`, `JsonSerializable`. See
[04-result-types.md](04-result-types.md) for the type system shape, or the
[Response shape](#response-shape) section below for the JSON envelope.

---

## Response shape

`WrapResult<TItem>` is a typed object with two public properties:

```php
final readonly class WrapResult
{
    public WrapMeta $meta;
    public array    $data;   // list<TItem>
}

final readonly class WrapMeta
{
    public int      $current;   // 1-based page number
    public int      $size;       // page size requested
    public int      $total;      // total items in the set
    public int      $pages;      // total pages (0 when total = 0)
    public ?int     $previous;   // previous page, or null on the first page
    public ?int     $next;       // next page, or null on the last page
}
```

JSON output (`json_encode($result)`):

```json
{
  "meta": {
    "current": 3,
    "size": 20,
    "total": 156,
    "pages": 8,
    "previous": 2,
    "next": 4
  },
  "data": [ ... ]
}
```

---

## Examples

### Repository input

```php
$result = Wrapper::paginator(
    UserRepository::instance()
        ->where(Qb::eq('status', 'active'))
        ->orderBy('id ASC'),
    limit: 20,
    page: 3,
);

// $result->meta->current  → 3
// $result->meta->total    → 156
// $result->meta->pages    → 8
// $result->data           → list<UserEntity>

return new JsonResponse($result);
```

### Array input

```php
$rows = loadFromCache();  // 1000 items
$result = Wrapper::paginator($rows, limit: 50, page: 1);

// $result->meta  → WrapMeta(current: 1, size: 50, total: 1000, pages: 20, previous: null, next: 2)
// $result->data  → 50 items (rows 0..49)
```

### With mapper

```php
$result = Wrapper::paginator(
    ProductRepository::instance()->orderBy('name ASC'),
    limit: 20,
    page: 1,
    mapper: fn (ProductEntity $p) => ProductResource::from($p),
);
// $result->data → list<ProductResource>
```

### With entity override

```php
$result = Wrapper::paginator(
    UserRepository::instance(),
    limit: 20,
    page: 1,
    entityClassName: UserSummary::class,
);
// $result->data → list<UserSummary>
```

---

## Boundary cases

```json
// First page:
{ "meta": { "current": 1, "size": 20, "total": 156, "pages": 8, "previous": null, "next": 2 }, "data": [...] }

// Last page:
{ "meta": { "current": 8, "size": 20, "total": 156, "pages": 8, "previous": 7, "next": null }, "data": [...] }

// Empty set:
{ "meta": { "current": 1, "size": 20, "total": 0, "pages": 0, "previous": null, "next": null }, "data": [] }
```

`previous` / `next` are `null` (not `0`) when no such page exists — friendly
for typed front-ends (`number | null` vs ambiguous `number | 0`).

---

## Wrapper vs Paginator — when to use which

|  | `Wrapper::paginator()` | `Paginator::repo()` / `::array()` |
|---|---|---|
| Meta shape | page-centric (`current`, `pages`, `previous`, `next`) | offset-centric (`offset`, `size`, `total`) |
| Return type | `WrapResult<TItem>` | `PaginationResult<TMeta, TItem>` |
| Field count | 6 fields in `meta` | 3 fields in `meta` |
| Best for | Numbered-page admin UIs, classical web pagination | API consumers, offset-based clients, infinite scroll |
| Convenience extras | computes `pages`, `previous`, `next` | none — client derives them |
| Performance | identical (delegates to `Paginator` internally) | — |

Both share the same underlying `COUNT + SELECT` execution. The only
difference is the response envelope.

---

## Implementation notes

`Wrapper::paginator()` is a stateless façade — it computes:

```php
$offset = $limit * ($page - 1);

$result = is_array($repo)
    ? Paginator::array($repo, $limit, $offset, $mapper)
    : Paginator::repo($repo, $limit, $offset, $entityClassName, $mapper);
```

then re-shapes the `PaginationResult` into a typed `WrapResult`. No SQL,
no state, no static fields — safe for concurrent calls in Swoole.

For SQL-backed input, the underlying `Paginator::repo()` performs the
`COUNT` **before** `findAll()` to avoid the repo `cleanCache()` wiping
WHERE/JOIN. See [01-offset-repo.md](01-offset-repo.md) for details.

---

## Side effects on `$repo`

For SQL input, `Wrapper::paginator()` calls `$repo->limit($size, $offset)` —
the repository state is mutated before queries run. If you plan to reuse
`$repo` after pagination, **clone it beforehand**:

```php
$base = UserRepository::instance()->where(Qb::eq('status', 'active'));
$page = Wrapper::paginator(clone $base, limit: 20);
// $base is untouched and ready for another use
$count = $base->count();
```

Arrays are passed by value in PHP — array input is never mutated externally.
