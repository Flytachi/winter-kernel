# Paginator::array() — Offset Pagination over Arrays

In-memory slicing of a pre-loaded collection. Pure function — no SQL, no I/O.

```php
public static function array(
    array $items,
    int $size,
    int $offset = 0,
    ?callable $mapper = null,
): PaginationResult
```

---

## Parameters

| Param | Description |
|-------|-------------|
| `$items` | Full collection to paginate over. Indexed or associative array; `array_slice` is used internally. |
| `$size` | Page size. Must be `>= 1` — throws `ValueError` otherwise. |
| `$offset` | Offset from the start of the array. Defaults to `0`. |
| `$mapper` | Optional per-item transformer applied to the sliced page only, not to the full input. Signature: `fn (TItem $item): mixed`. |

Returns: `PaginationResult<PaginationMeta, TItem>`. See [04-result-types.md](04-result-types.md).

---

## Basic usage

```php
$rows = loadAllFromCache();      // assume 1000 items
$page = Paginator::array($rows, size: 50, offset: 100);

// $page->meta  → PaginationMeta(offset: 100, size: 50, total: 1000)
// $page->data  → 50 items, $rows[100]..$rows[149]
```

---

## With a mapper

```php
$page = Paginator::array(
    items: $rawRows,
    size: 20,
    offset: 0,
    mapper: fn (array $r) => Row::from($r),
);
// data → list<Row>  (only the 20 sliced items were transformed)
```

The mapper applies to the **sliced page**, not the full input — that's the
whole point of using it instead of `array_map($mapper, $rows)` upstream.

---

## When to use

- Data already loaded (cache hit, prior `findAll()`, API response, file parse).
- Small/medium sets where re-querying with `LIMIT/OFFSET` is wasted overhead.
- Tests / fixtures where SQL is not involved.

For DB-backed pagination, use [01-offset-repo.md](01-offset-repo.md) — array
pagination on a freshly-loaded full result set defeats the purpose of `LIMIT`.

---

## Edge cases

- `$size <= 0` → `ValueError`.
- `$offset >= count($items)` → `data` is `[]`, `total` is `count($items)`.
- `$offset` is **not** validated `>= 0` here (unlike `Paginator::repo()`).
  PHP `array_slice` with negative offset reads from the end — usually not what
  you want for pagination. Caller is responsible for guarding this if it
  matters.

---

## Notes

- `total = count($items)` — always reflects the full input, regardless of slicing.
- Result implements `JsonSerializable` — the meta/data shape is identical to
  `repo()` output. Front-end code that consumes both can use the same parser.

---

## Practical use case — paginating a cached list

```php
final class TagListController
{
    public function index(Request $req): JsonResponse
    {
        // Tag catalogue is small and rarely changes — cached for the day.
        $tags = $this->cache->get(
            'tags:all',
            ttl: 3600,
            loader: fn () => TagRepository::instance()->orderBy('name ASC')->findAll(),
        );

        $page = max(1, (int) $req->query('page', 1));
        $size = 50;

        $result = Paginator::array(
            items: $tags,
            size: $size,
            offset: ($page - 1) * $size,
            mapper: fn (TagEntity $t) => TagResource::from($t),
        );

        return new JsonResponse($result);
    }
}
```

No SQL roundtrip per request — the full list lives in cache, paginator just
slices the right window. Compare with `Paginator::repo()` which would issue
`SELECT ... LIMIT/OFFSET` + `COUNT(*)` every time even for a 200-row table.

Use array pagination when:
- The full dataset comfortably fits in memory (hundreds, maybe low thousands of rows)
- The data is cacheable for the request lifecycle or longer
- The cost of loading once + slicing > cost of two SQL queries per request
