# Winter Pagination — Documentation Overview

The **Pagination** unit provides three strategies for paginating data:

- **Offset over repository** ({@see Paginator::repo()}) — typical numbered-page
  paginator with a `COUNT(*)` for total. SQL-backed, suited for admin UIs and
  filtered lists where total page count matters.
- **Offset over array** ({@see Paginator::array()}) — pure in-memory slicing,
  no SQL. For collections already loaded into memory.
- **Cursor (bidirectional)** ({@see Paginator::cursor()}) — opaque before/after
  cursors, no `COUNT`. Constant cost regardless of set size, suited for feeds
  and infinite scroll. _Experimental — see [03-cursor.md](03-cursor.md)._

All strategies return a `PaginationResult<TMeta, TData>` — a `JsonSerializable`
container with `meta` and `data` fields ready to ship as an API payload.

For page-centric UIs ("Page 3 of 8" + prev/next links), {@see Wrapper::paginator()}
exposes the same two SQL strategies in a `{current, pages, previous, next}`
shape on top of `Paginator`. See [05-wrapper.md](05-wrapper.md).

---

## How the pieces fit together

```
Paginator (final, static)                          — offset-centric, modern
    ├── ::repo(repo, size, offset, entity?, mapper?)   → PaginationResult<PaginationMeta, …>
    ├── ::array(items, size, offset, mapper?)          → PaginationResult<PaginationMeta, …>
    └── ::cursor(repo, size, before?, after?, entity?) → PaginationResult<PaginationMetaCursor, …>

Result types:
    ├── PaginationResult<TMeta, TItem>   readonly, JsonSerializable
    ├── PaginationMeta                   { offset, size, total }
    └── PaginationMetaCursor             { size, beforeCursor, afterCursor, hasNextPage, hasPrevPage }

Wrapper (final, static)                            — page-centric, for numbered-page UIs
    └── ::paginator(repo|array, limit, page?, entity?, mapper?)
                                                   → array{ meta: {current, size, total, pages, previous, next}, data }
```

---

## Choosing a strategy

| Use case | Strategy | Why |
|----------|----------|-----|
| Admin list with page numbers + prev/next links | `Wrapper::paginator()` | Page-centric shape with `current/pages/previous/next` ready for UI |
| API consumer with offset-based clients | `Paginator::repo()` | Modern minimal `{offset, size, total}` |
| Filtered search results | `Paginator::repo()` or `Wrapper` | Both expose total — pick by client shape |
| Already-loaded collection | `Paginator::array()` or `Wrapper` (array input) | No SQL roundtrip |
| Activity feed / timeline | `Paginator::cursor()` | No `COUNT` cost; stable across writes |
| Infinite-scroll list | `Paginator::cursor()` | Same; opaque cursors hide pagination state |

---

## Quick start

```php
use Flytachi\Winter\K2\Unit\Pagination\Paginator;

// 1. Repository-backed offset pagination (most common)
$result = Paginator::repo(
    UserRepository::instance()->where(Qb::eq('status', 'active'))->orderBy('id ASC'),
    size: 20,
    offset: 0,
);
// $result->meta   → PaginationMeta(offset: 0, size: 20, total: 156)
// $result->data   → list<UserEntity>
echo json_encode($result);
// {"meta":{"offset":0,"size":20,"total":156},"data":[...]}

// 2. Array slicing
$page = Paginator::array($rows, size: 50, offset: 100);
// $page->meta     → PaginationMeta(offset: 100, size: 50, total: count($rows))

// 3. Cursor-based (experimental — see 03-cursor.md)
$result = Paginator::cursor(
    PostRepository::instance()->orderBy('id DESC'),
    size: 20,
);
```

---

## Documents

1. [01-offset-repo.md](01-offset-repo.md) — `Paginator::repo()` in depth
2. [02-array.md](02-array.md) — `Paginator::array()` for in-memory data
3. [03-cursor.md](03-cursor.md) — `Paginator::cursor()` bidirectional cursors
4. [04-result-types.md](04-result-types.md) — `PaginationResult`, `PaginationMeta`, `PaginationMetaCursor`
5. [05-wrapper.md](05-wrapper.md) — `Wrapper::paginator()` page-centric shape
