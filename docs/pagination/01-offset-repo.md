# Paginator::repo() — Offset Pagination over Repository

Offset-based pagination of a `RepositoryViewInterface`. Issues two queries:
the **COUNT** for `total`, and the **page SELECT** with `LIMIT/OFFSET`.

```php
public static function repo(
    RepositoryViewInterface $repo,
    int $size,
    int $offset = 0,
    ?string $entityClassName = null,
    ?callable $mapper = null,
): PaginationResult
```

---

## Parameters

| Param | Description |
|-------|-------------|
| `$repo` | Source repository with `WHERE / JOIN / ORDER BY / …` already applied. The paginator adds `LIMIT/OFFSET` and runs `COUNT` against it. |
| `$size` | Page size. Must be `>= 1` — throws `ValueError` otherwise. |
| `$offset` | Offset from the start of the result set. Defaults to `0`. |
| `$entityClassName` | Per-call hydration override. Forwarded to `findAll()`. Forces rows into a different class than the repo default. `null` keeps the repo's configured entity. |
| `$mapper` | Optional `array_map`-style transformer applied to the fetched rows. Signature: `fn (TEntity $item): mixed`. When provided, cast the result's `data` to the mapper's return type in your call site. |

Returns: `PaginationResult<PaginationMeta, TEntity>` — or `PaginationResult<PaginationMeta, TOverride>` when `$entityClassName` is given. See [04-result-types.md](04-result-types.md).

---

## Basic usage

```php
$result = Paginator::repo(
    UserRepository::instance()
        ->where(Qb::eq('status', 'active'))
        ->orderBy('id ASC'),
    size: 20,
    offset: 40,
);

// $result->meta  → PaginationMeta(offset: 40, size: 20, total: 156)
// $result->data  → list<UserEntity>  (rows 41..60)

return json_encode($result);
// {"meta":{"offset":40,"size":20,"total":156},"data":[{...},{...}, ...]}
```

---

## With a mapper

Transform each row before assembly — common for converting entities into
API-friendly DTOs / resources:

```php
$result = Paginator::repo(
    ProductRepository::instance()->orderBy('name ASC'),
    size: 50,
    offset: 0,
    mapper: fn (ProductEntity $p) => ProductResource::from($p),
);
// data → list<ProductResource>
```

The mapper runs **after** the page rows are fetched, so it only sees the
current page items — never the full set.

---

## With an entity override

When the same query needs to hydrate into a different class for this call only:

```php
$result = Paginator::repo(
    UserRepository::instance()->where(Qb::eq('role', 'admin')),
    size: 20,
    entityClassName: UserSummary::class,
);
// data → list<UserSummary>
```

The override affects **hydration only**, not the SELECT clause. To select a
different column set, call `->select(...)` on the repo explicitly before
passing it in. See [12-view-fetch.md](../ppa/12-view-fetch.md) for repo finder
semantics.

---

## How the two queries are built

The paginator runs the **COUNT first**, then `findAll()`:

1. **COUNT** — `buildSql()` with `ignoreParts: ['order', 'limit', 'offset', 'for']`,
   wrapped as `SELECT COUNT(*) FROM (<subquery>) AS tmp`. Total counts the full
   filtered set, ignoring pagination clauses.
2. **Page SELECT** — `findAll()` runs the normal query with `LIMIT/OFFSET`,
   hydrates rows, then calls `cleanCache()` to reset the repo state.

> **Why COUNT first?** `findAll()` calls `cleanCache()` at the end, which wipes
> `WHERE/JOIN/...` from the repo state. Counting after `findAll()` would give
> the total of all rows in the table, ignoring filters. The order matters and
> is part of the contract — do not reverse it.

---

## Side effects on `$repo`

`repo()` calls `$repo->limit($size, $offset)`, so the repository state is
mutated before the queries run. After `findAll()` returns, `cleanCache()` has
already reset state to the class defaults.

If the caller wants to reuse `$repo` after pagination with the same WHERE/JOIN
already applied, **clone it beforehand**:

```php
$base = UserRepository::instance()->where(Qb::eq('status', 'active'));
$page = Paginator::repo(clone $base, size: 20);
// $base is untouched and ready for another use
$count = $base->count();
```

---

## Edge cases

- `$size <= 0` → `ValueError`. There is no implicit fallback to a "default page size".
- `$size = 1, $offset = 0` → valid, returns up to one row.
- Empty result set → `data` is `[]`, `total` is `0`, page metadata still returned.
- `$offset >= total` → `data` is `[]`, but `total` reflects the actual count.
  Clients should check `offset + size < total` to determine `hasNext`.

---

## Notes

- `total` reflects the count **after** WHERE/JOIN/HAVING/GROUP BY, **before**
  LIMIT/OFFSET — i.e. the number of matching rows the user could in principle
  page through.
- `PaginationMeta` intentionally does not store derived values (`hasNext`,
  `totalPages`). Compute them at the call site — see
  [04-result-types.md](04-result-types.md).
- For collections already in memory, use [02-array.md](02-array.md) instead
  to avoid the SQL roundtrip.
