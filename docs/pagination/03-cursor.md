# Paginator::cursor() — Bidirectional Cursor Pagination

Cursor-based (before/after) pagination, no `COUNT`. Each page returns opaque
cursors that the client echoes back to navigate forward or backward.

```php
final public static function cursor(
    RepositoryViewInterface $repo,
    int $size,
    ?string $cursorAfter = null,
    ?string $cursorBefore = null,
    ?string $entityClassName = null,
): PaginationResult
```

> **Status: experimental.** The current implementation has known issues — see
> [Known limitations](#known-limitations) below. The signature and result type
> are stable; the body needs work.

---

## Parameters

| Param | Description |
|-------|-------------|
| `$repo` | Source repository with `WHERE / ORDER BY id …` already applied. The paginator adds `LIMIT $size + 1` and an `id`-based cursor condition. |
| `$size` | Page size. Must be `> 0` — throws `\TypeError` otherwise. |
| `$cursorAfter` | Opaque cursor — fetch the page **after** this position. `null` for the first forward page. |
| `$cursorBefore` | Opaque cursor — fetch the page **before** this position. `null` for the latest items. |
| `$entityClassName` | Per-call hydration override, forwarded to `findAll()`. |

Returns: `PaginationResult<PaginationMetaCursor, TEntity>`. See [04-result-types.md](04-result-types.md).

---

## How cursors work

A cursor is an opaque, base64-encoded snapshot of position in the ordered set:

```
base64(json({"id": 123}))   →   "eyJpZCI6MTIzfQ=="
```

The encoding is an **implementation detail** — clients must not parse it.
The paginator decodes and re-encodes cursors internally; clients only echo them
back via `$cursorAfter` / `$cursorBefore`.

The cursor strategy assumes the result set is **ordered by `id` DESC** (newest
first). Different orderings would need code changes.

---

## Forward navigation (after)

First page — no cursor:

```php
$page1 = Paginator::cursor(
    PostRepository::instance()->orderBy('id DESC'),
    size: 20,
);
// $page1->meta->afterCursor   → "eyJpZCI6OTgxfQ==" (cursor for next page)
// $page1->meta->hasNextPage   → true
// $page1->meta->hasPrevPage   → false (this is the first page)
```

Next page — pass `afterCursor` from previous response:

```php
$page2 = Paginator::cursor(
    PostRepository::instance()->orderBy('id DESC'),
    size: 20,
    cursorAfter: $page1->meta->afterCursor,
);
// SQL: WHERE id < 981 ORDER BY id DESC LIMIT 21
```

---

## Backward navigation (before)

When the client wants to scroll up (back toward newer items):

```php
$prev = Paginator::cursor(
    PostRepository::instance()->orderBy('id DESC'),
    size: 20,
    cursorBefore: $current->meta->beforeCursor,
);
// SQL: WHERE id > 1000 ORDER BY id DESC LIMIT 21
// (results reversed internally to maintain DESC order in the page)
```

The paginator fetches `LIMIT $size + 1` — the extra row is used to detect
whether another page exists in the navigation direction, then trimmed before
returning.

---

## JSON output

```json
{
  "meta": {
    "size": 20,
    "has_next_page": true,
    "has_previous_page": false,
    "before_cursor": "eyJpZCI6MTAwMH0=",
    "after_cursor": "eyJpZCI6OTgxfQ=="
  },
  "data": [
    { "id": 1000, "title": "..." },
    ...
    { "id": 981,  "title": "..." }
  ]
}
```

No `total` field — cursor pagination intentionally skips the `COUNT` to stay
constant-cost.

---

## When to use cursor over offset

- **Stable across writes** — new rows inserted between page loads do not shift
  page boundaries (offset paginator would show duplicates or skip rows).
- **Constant cost** — no `COUNT(*)` against the full set.
- **Infinite scroll / feeds** — natural fit for "load more" UX.

Avoid when:
- The UI needs "Page 5 of 12" with jump-to-page links (cursor can only step
  forward/backward, not random-access). Use [01-offset-repo.md](01-offset-repo.md)
  instead.
- The total count is part of the response contract.

---

## Known limitations

This method is **experimental** in the current codebase:

1. **Cursor field is hardcoded to `id`** — the JSON envelope contains only
   `{"id": ...}`, and the internal `WHERE` clause references the `id` column
   literally. Tables with a different primary key column require code changes.
2. **Ordering is assumed `id DESC`** — the before/after logic is hard-coded
   for descending order. ASC ordering would invert the meaning of before/after.
3. **`where()` signature mismatch** — the internal calls use a positional
   3-arg form (`$repo->where('id', '<', $id)`) that does not match the
   repository's `where(?Qb $qb)` contract. This is a known pre-existing bug
   and may produce runtime errors depending on PHP error reporting settings.
4. **No `total` field by design** — `PaginationMetaCursor` deliberately omits
   it. If you need the total too, use `repo()` instead (and accept the
   `COUNT` cost).

These are tracked for resolution in a separate iteration. Until then, prefer
`repo()` for production paths that need pagination on filtered/ordered queries.
