# Paginator::cursor() — Bidirectional Cursor Pagination

Cursor-based (keyset / seek-method) pagination — no `COUNT`, constant cost
regardless of set size. Each page returns opaque `cursorPrev` / `cursorNext`
tokens that the client echoes back via a single `$cursor` parameter —
navigation direction is encoded into the token itself.

**No `hasNext` / `hasPrev` flags** — a cursor is `null` if and only if
navigation in that direction is unavailable. Single source of truth.

```php
final public static function cursor(
    RepositoryViewInterface $repo,
    int $size,
    CursorKey $key,
    ?string $cursor = null,
    ?string $entityClassName = null,
    ?callable $mapper = null,
): PaginationResult
```

---

## Parameters

| Param | Description |
|-------|-------------|
| `$repo` | Source repository with `WHERE / JOIN / GROUP BY / …` pre-applied. **Must not have `orderBy()` set** — the paginator owns ORDER BY (any prior ORDER is overwritten). |
| `$size` | Page size. Must be `>= 1` — throws `ValueError` otherwise. |
| `$key` | `CursorKey` value-object describing the ordering column(s). See [Key shape](#key-shape) below. |
| `$cursor` | Opaque cursor token from a previous response's `cursorPrev` or `cursorNext`. `null` for the first page. Direction (forward / backward) is encoded inside the token. |
| `$entityClassName` | Per-call hydration override. The override class **must** expose every column referenced by `$key` as a property. |
| `$mapper` | Optional per-item transformer applied to the page before assembly. `fn (TEntity $item): mixed`. |

Returns `PaginationResult<PaginationMetaCursor, TEntity>`.

---

## Key shape

### Single column

```php
new CursorKey('id', Sort::Desc);
```

Standard "newest first" timeline. Works whenever `id` is monotonically growing
and unique.

### Composite (stable across ties) — recursive form

When the primary cursor column has duplicates (`created_at` granular to
seconds, soft-deleted flags, etc.), a single-column cursor can skip or
duplicate rows across page boundaries. Use a `tiebreaker`:

```php
new CursorKey(
    column: 'created_at',
    direction: Sort::Desc,
    tiebreaker: new CursorKey('id', Sort::Desc),
);
```

Generated `ORDER BY`:
```sql
ORDER BY created_at DESC, id DESC
```

Generated cursor `WHERE` for forward navigation past `[‘2026-05-22 12:00:00’, 456]`:
```sql
WHERE created_at < '2026-05-22 12:00:00'
   OR (created_at = '2026-05-22 12:00:00' AND id < 456)
```

### Composite — flat form via `compose()`

For 3+ levels or runtime-built keys (dynamic ORDER from a request), the
recursive form gets ugly. Use `CursorKey::compose(...)`:

```php
$key = CursorKey::compose(
    new CursorKey('p.name',        Sort::Asc, alias: 'product_name'),
    new CursorKey('c.name',        Sort::Asc, alias: 'category_name'),
    new CursorKey('p.id',          Sort::Asc),
);
```

The first key becomes the primary sort, each subsequent one a tiebreaker level.
Passed keys must be leaves (no `tiebreaker:` argument) — otherwise
`InvalidArgumentException`.

Dynamic example — building `CursorKey` from a request's sort spec:

```php
$leaves = [];
foreach ($request->sortModel as $s) {
    $leaves[] = new CursorKey(
        column: $fieldMap[$s->field],          // 'productName' → 'p.name'
        direction: $s->dir === 'asc' ? Sort::Asc : Sort::Desc,
        alias: $s->field,                       // hydrated property
    );
}
$leaves[] = new CursorKey('id', Sort::Asc);    // always-stable final tiebreaker
$key = CursorKey::compose(...$leaves);
```

### `alias` — when SELECT uses `AS`

`CursorKey::$column` is the **SQL** expression used in `ORDER BY` / `WHERE`.
`CursorKey::$alias` is the **PHP** property name on the hydrated entity used
to read cursor values back out.

When `alias` is `null`, the paginator strips any table prefix from `column`
(`'p.name'` → `'name'`). That covers the simple case `SELECT p.name`.

But when the SELECT renames the column —

```sql
SELECT p.name AS product_name, ...
```

— the hydrated entity has `$row->product_name`, not `$row->name`. Auto-strip
gives `'name'` and `extractRowValues` throws. Set `alias` explicitly:

```php
new CursorKey('p.name', Sort::Asc, alias: 'product_name')
//             ↑                          ↑
//      SQL ORDER/WHERE             hydrated entity property
```

| SELECT shape | `alias` needed? |
|--------------|-----------------|
| `SELECT id, name` | No — auto |
| `SELECT p.id, p.name` (table prefix) | No — auto-strip to `id` / `name` |
| `SELECT p.name AS product_name` | **Yes** — `alias: 'product_name'` |
| `SELECT COUNT(*) AS total` | **Yes** — `alias: 'total'` (and `column` should match the SELECT expression) |

---

## How cursors work

A cursor is `base64(json({"s": "<sig>", "v": [...values], "d": "<f|b>"}))` —
opaque to clients:

```
base64({"s":"a3f2b1c8","v":[123],"d":"f"})  →  "eyJzIjoiYTNmMmIxYzgiLCJ2IjpbMTIzXSwiZCI6ImYifQ=="
```

- `s` — 8-character `xxh3` signature of the `CursorKey` shape (column + direction,
  recursive for tiebreakers).
- `v` — cursor values in the order produced by `CursorKey::flatten()`.
- `d` — navigation direction (`f` = forward / after, `b` = backward / before).

The signature is verified on decode. A token issued under a different
`CursorKey` (e.g. you redeployed with a tiebreaker added) is rejected via
`InvalidCursorException` — clients see a `400` instead of silent garbage.

Encoding direction into the token means the API takes a **single** `$cursor`
parameter: the server reads navigation direction from the token. Clients just
echo back whichever cursor they want to follow — `cursorPrev` (to go back)
or `cursorNext` (to go forward).

---

## Forward navigation

First page — no cursor:

```php
$page1 = Paginator::cursor(
    PostRepository::instance()->where(Qb::eq('published', true)),
    size: 20,
    key: new CursorKey('id', Sort::Desc),
);
// $page1->meta->cursorNext  → "eyJ…ImYifQ==" (forward-tagged cursor for next page)
// $page1->meta->cursorPrev  → null (first page — no previous)
```

Next page — pass `cursorNext` from previous response back as `$cursor`:

```php
$page2 = Paginator::cursor(
    PostRepository::instance()->where(Qb::eq('published', true)),
    size: 20,
    key: new CursorKey('id', Sort::Desc),
    cursor: $page1->meta->cursorNext,         // direction='f' inside token
);
// SQL: ... WHERE published = true AND id < 981 ORDER BY id DESC LIMIT 21
```

---

## Backward navigation

Scrolling back toward newer items — pass `cursorPrev` as `$cursor`:

```php
$prev = Paginator::cursor(
    PostRepository::instance()->where(Qb::eq('published', true)),
    size: 20,
    key: new CursorKey('id', Sort::Desc),
    cursor: $current->meta->cursorPrev,       // direction='b' inside token
);
// SQL: ... WHERE published = true AND id > 1000 ORDER BY id ASC LIMIT 21
//      ↑ ORDER BY inverted during selection so the DB returns rows
//        immediately above the cursor (not the highest 20 above)
// Then array_reverse'd in PHP to maintain the user-visible DESC order.
```

The paginator transparently inverts `ORDER BY` for backward navigation —
the user never sees this. The result is always in the orientation declared
by `CursorKey`.

---

## JSON output

```json
{
  "meta": {
    "size": 20,
    "cursorPrev": null,
    "cursorNext": "eyJzIjoiYTNmMmIxYzgiLCJ2IjpbOTgxXSwiZCI6ImYifQ=="
  },
  "data": [
    { "id": 1000, "title": "..." },
    ...
    { "id": 981,  "title": "..." }
  ]
}
```

No `total` field — cursor pagination intentionally skips the `COUNT` to stay
constant-cost. No `has*` flags — `cursor* === null` already encodes
"no page in that direction".

---

## Cursor emission rules

A cursor is `null` if and only if navigation in that direction is unavailable.

|  | `cursorPrev` | `cursorNext` |
|---|---|---|
| **First page** (no `$cursor`) | `null` (no previous, ever) | token (if more rows below) or `null` (last page) |
| **Forward** (`cursorNext` of prior page) | token (came from somewhere — always have it) | token (if more rows below) or `null` (last page) |
| **Backward** (`cursorPrev` of prior page) | token (if more rows above) or `null` (start) | token (came from forward — always have it) |
| **Empty page** | `null` | `null` |

Client logic — no conditional flag check needed:

```js
if (meta.cursorPrev) showPrevButton(meta.cursorPrev);
if (meta.cursorNext) showNextButton(meta.cursorNext);
```

---

## When to use cursor over offset

- **Stable across writes** — new rows inserted between page loads do not shift
  page boundaries.
- **Constant cost** — no `COUNT(*)` against the full set.
- **Infinite scroll / feeds** — natural fit for "load more" UX.

Avoid when:
- The UI needs "Page 5 of 12" with jump-to-page links (cursor only steps
  forward / backward, not random-access). Use [01-offset-repo.md](01-offset-repo.md).
- The total count is part of the response contract.

---

## Constraints

- **Repository must not have `orderBy()` pre-applied.** The paginator owns
  ORDER BY; any prior `ORDER BY` is overwritten without warning. Pre-applied
  `WHERE / JOIN / GROUP BY / HAVING` are preserved (cursor `WHERE` is added
  via `andWhere()`).
- **Every cursor column must be in the SELECT and reachable on the hydrated
  entity** under the expected `alias` (or the auto-stripped column name).
  Missing properties throw `LogicException` at cursor-encoding time.
- **Cursor key shape changes are breaking** — changes to `column` or
  `direction` (in any tiebreaker) invalidate old client cursors via
  signature mismatch (`InvalidCursorException` → HTTP 400). Changes to
  `alias` alone do **not** invalidate cursors (alias is excluded from the
  signature — it is a runtime read concern, not a contract about data).

---

## Errors

| Exception | Cause |
|-----------|-------|
| `ValueError` | `$size < 1`. |
| `InvalidCursorException` | Token malformed, not base64/JSON, missing fields, unknown direction value, or signature mismatch. Treat as HTTP 400. |
| `LogicException` | Hydrated row is missing a cursor alias property — usually a misconfigured `CursorKey` (wrong `alias`) or wrong entity class. |

---

# Real-world patterns

The rest of this document covers production-ready patterns. Cursor pagination
is the right tool for **high-throughput, write-heavy** datasets — feeds,
event logs, chat history, notification streams — where offset pagination's
`COUNT` cost and shifting-page-boundary problems hurt the most.

---

## Pattern: Activity feed (Twitter-like timeline)

The canonical cursor pagination scenario. New items keep arriving at the
top; users scroll down to load older items, pull-to-refresh to see new ones.

```php
final class FeedController
{
    public function index(Request $req): JsonResponse
    {
        try {
            $result = Paginator::cursor(
                repo: PostRepository::instance('p')
                    ->select('p.id, p.user_id, p.body, p.created_at')
                    ->joinInner(UserRepository::instance('u'), 'u.id = p.user_id')
                    ->where(Qb::eq('p.is_deleted', false))
                    ->andWhere(Qb::eq('u.is_banned', false)),
                size: min((int) $req->query('size', 20), 50),    // cap at 50
                key: new CursorKey('p.id', Sort::Desc),
                cursor: $req->query('cursor'),
                mapper: fn (PostEntity $p) => PostResource::from($p),
            );
        } catch (InvalidCursorException) {
            return new JsonResponse(['error' => 'invalid_cursor'], 400);
        }

        return new JsonResponse($result);
    }
}
```

**Why this works at scale**:
- No `COUNT` — millions of rows don't slow this down
- Page query is `WHERE id < $cursor ORDER BY id DESC LIMIT 21` — uses the
  primary key index, response time is constant regardless of dataset size
- New posts inserted during pagination don't shift boundaries (they appear
  on the "top" only after a pull-to-refresh)

**Required index**:
```sql
-- The PK index on posts.id is enough for ordering;
-- composite indexes only needed when filtering AND ordering together:
CREATE INDEX idx_posts_user_deleted_id ON posts (user_id, is_deleted, id DESC);
```

---

## Pattern: Chat / message history

Slightly different — initial load shows newest messages; user scrolls **up**
to load older. The "load more" action conceptually goes backward in time
but uses `cursorNext` semantics if ordering is `id DESC`.

```php
final class ChatController
{
    public function messages(int $roomId, Request $req): JsonResponse
    {
        $result = Paginator::cursor(
            repo: MessageRepository::instance('m')
                ->select('m.id, m.author_id, m.body, m.created_at')
                ->where(Qb::eq('m.room_id', $roomId)),
            size: 50,
            key: new CursorKey('m.id', Sort::Desc),     // newest first
            cursor: $req->query('cursor'),
        );

        return new JsonResponse($result);
    }
}
```

Client UX:
- Initial load: `GET /rooms/42/messages` → 50 newest, `cursorNext` for "load older"
- User scrolls up: `GET /rooms/42/messages?cursor=cursorNext` → 50 more, older
- New message arrives via WebSocket → prepend to UI (no pagination roundtrip)

For "jump to message X" use cases (linking to a specific message in history)
— cursor doesn't handle this directly. Use a separate `?around=<messageId>`
endpoint that fetches window around the target.

---

## Pattern: Stable composite — high write rate

When the primary cursor column has duplicates (timestamps with second
precision, scores in a leaderboard, etc.), **always add an `id` tiebreaker**.
Otherwise concurrent inserts during pagination cause skipped/duplicated rows.

```php
$result = Paginator::cursor(
    repo: NotificationRepository::instance()
        ->select('id, user_id, type, payload, created_at')
        ->where(Qb::eq('user_id', $userId))
        ->andWhere(Qb::eq('seen', false)),
    size: 30,
    key: new CursorKey(
        column: 'created_at',
        direction: Sort::Desc,
        tiebreaker: new CursorKey('id', Sort::Desc),
    ),
    cursor: $req->query('cursor'),
);
```

**Why the tiebreaker is mandatory under load**:

Without it, two notifications inserted in the same second can produce:
```sql
WHERE created_at < '2026-05-22 14:30:15'
-- page 1: returns [A@14:30:16, B@14:30:16]
-- cursor encodes 14:30:16
-- page 2: returns [B@14:30:16 AGAIN, C@14:30:14]  ← B appears twice
```

With `id` tiebreaker (`v: ['14:30:16', 5421]`):
```sql
WHERE created_at < '14:30:16'
   OR (created_at = '14:30:16' AND id < 5421)
-- page 2: returns [C@14:30:14]  ← B correctly excluded
```

**Required index** (PostgreSQL):
```sql
CREATE INDEX idx_notifications_user_unread
    ON notifications (user_id, seen, created_at DESC, id DESC)
    WHERE seen = false;   -- partial index for the hot path
```

---

## Pattern: Audit log / event stream

Append-only data, often partitioned by tenant/account. Cursor pagination
is the only sane choice — `OFFSET 1000000` on an audit table is a
table scan.

```php
final class AuditController
{
    public function events(int $tenantId, Request $req): JsonResponse
    {
        $repo = AuditEventRepository::instance('a')
            ->select('a.id, a.actor_id, a.action, a.target, a.created_at')
            ->where(Qb::eq('a.tenant_id', $tenantId));

        if ($req->query('actor')) {
            $repo->andWhere(Qb::eq('a.actor_id', (int) $req->query('actor')));
        }
        if ($req->query('action')) {
            $repo->andWhere(Qb::eq('a.action', $req->query('action')));
        }
        if ($req->query('from')) {
            $repo->andWhere(Qb::gte('a.created_at', $req->query('from')));
        }

        return new JsonResponse(Paginator::cursor(
            repo: $repo,
            size: 100,
            key: new CursorKey('a.id', Sort::Desc),
            cursor: $req->query('cursor'),
        ));
    }
}
```

Even with filters, cursor stays O(log n) per page thanks to indexes —
unlike offset which scans up-to-and-including OFFSET rows.

---

## Pattern: Dynamic ORDER from request (data grid)

User clicks column headers to re-sort. Build `CursorKey` from the request's
sort spec at runtime, **always appending a stable tiebreaker** to ensure
deterministic ordering when the user-chosen column has ties.

```php
final class StockGridController
{
    /** @var array<string, array{0: string, 1: string}> field → [SQL column, hydrated alias] */
    private const FIELD_MAP = [
        'productId'    => ['i.product_id', 'product_id'],
        'productName'  => ['p.name',        'product_name'],
        'categoryName' => ['c.name',        'category_name'],
        'brandName'    => ['b.name',        'brand_name'],
        'quantity'     => ['i.quantity',    'quantity'],
        'cost'         => ['i.cost',        'cost'],
    ];

    public function index(StockGridRequest $request): JsonResponse
    {
        $repo = ItemRepository::instance('i')
            ->select(
                'i.id, i.product_id, p.name product_name, '
                . 'p.category_id, c.name category_name, '
                . 'p.brand_id, b.name brand_name, '
                . 'i.quantity, i.cost'
            )
            ->joinLeft(ProductRepository::instance('p'),  'p.id = i.product_id')
            ->joinLeft(CategoryRepository::instance('c'), 'c.id = p.category_id')
            ->joinLeft(BrandRepository::instance('b'),    'b.id = p.brand_id')
            ->where(Qb::eq('i.warehouse_id', $request->warehouseId));

        if ($request->search) {
            $repo->andWhere(Qb::like('p.name', "%$request->search%", true));
        }

        // Build composite CursorKey from sort spec
        $leaves = [];
        foreach ($request->sortModel ?? [] as $s) {
            $map = self::FIELD_MAP[$s->field] ?? null;
            if ($map === null) continue;
            [$col, $alias] = $map;
            $leaves[] = new CursorKey(
                column: $col,
                direction: $s->dir === 'desc' ? Sort::Desc : Sort::Asc,
                alias: $alias,
            );
        }
        // Stable final tiebreaker — independent of user choice
        $leaves[] = new CursorKey('i.id', Sort::Asc, alias: 'id');

        $result = Paginator::cursor(
            repo: $repo,
            size: $request->size,
            key: CursorKey::compose(...$leaves),
            cursor: $request->cursor,
            mapper: fn ($item) => StockItemResource::from($item),
        );

        return new JsonResponse($result);
    }
}
```

**Important nuance**: changing the sort columns on the front-end **invalidates
old cursors** (signature mismatch → `InvalidCursorException`). The front-end
must drop its stored cursor whenever the user clicks a column header to
re-sort.

---

## Pattern: Multi-tenant feeds with permission scoping

Common in B2B apps — pagination scoped by user's access rights.

```php
$repo = ProjectRepository::instance('p')
    ->select('p.id, p.name, p.status, p.updated_at')
    ->joinInner(
        ProjectMemberRepository::instance('pm')
            ->select('pm.project_id')
            ->where(Qb::eq('pm.user_id', $currentUser->id)),
        'pm.project_id = p.id'
    )
    ->where(Qb::neq('p.status', 'archived'));

return Paginator::cursor(
    repo: $repo,
    size: 25,
    key: new CursorKey(
        column: 'p.updated_at',
        direction: Sort::Desc,
        tiebreaker: new CursorKey('p.id', Sort::Desc),
    ),
    cursor: $req->query('cursor'),
);
```

The permission JOIN doesn't change cursor semantics — paginator just adds
its WHERE on top via `andWhere()`.

---

## Pattern: Cursor + cache headers

Cursor pagination is **cache-friendly**: the same `?cursor=X` always returns
the same rows (unless rows are deleted/modified). Set long `Cache-Control`
on cursor-paginated pages:

```php
$result = Paginator::cursor(/* ... */);

return new JsonResponse($result)
    ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=300')
    ->withHeader('ETag', '"' . hash('xxh3', json_encode($result)) . '"');
```

Compare with offset pagination, where `?page=2` may show different rows
between requests if new items were inserted — caching is much trickier.

---

## Pattern: Pull-to-refresh + load-more (mobile)

The canonical mobile feed UX:

```php
// Endpoint serves both initial load AND load-more via the same handler
public function feed(Request $req): JsonResponse
{
    $result = Paginator::cursor(
        repo: PostRepository::instance()->where(Qb::eq('published', true)),
        size: 20,
        key: new CursorKey('id', Sort::Desc),
        cursor: $req->query('cursor'),
    );

    return new JsonResponse($result);
}
```

Mobile client:

```js
// State
let feed = [];          // current items
let cursorNext = null;  // for "load more" (older)
let cursorPrev = null;  // for "pull to refresh" (newer)

// Initial load
const r1 = await fetch('/feed');
feed       = r1.data;
cursorNext = r1.meta.cursorNext;   // null if list fits in one page
cursorPrev = r1.meta.cursorPrev;   // null on first load (no previous)

// Load more (older items, append at bottom) — only if there's a next page
if (cursorNext) {
  const r2 = await fetch(`/feed?cursor=${cursorNext}`);
  feed       = [...feed, ...r2.data];
  cursorNext = r2.meta.cursorNext; // becomes null on the last page
}

// Pull to refresh — show items NEWER than current top
// cursorPrev is null on first load; for the very first pull-to-refresh
// just re-fetch '/feed' to reset.
if (cursorPrev) {
  const r3 = await fetch(`/feed?cursor=${cursorPrev}`);
  feed       = [...r3.data, ...feed];   // prepend
  cursorPrev = r3.meta.cursorPrev;
}
```

Note how the client never branches on a `has*` flag — `if (cursor)` is the
boolean. One field per direction, doing both jobs.

---

## Performance — index recommendations

Cursor pagination is **only as fast as your index** lets it be. The key
insight: the `WHERE (cursor) ORDER BY (key)` pair must match an index.

### Single-column cursor

```sql
-- Posts ordered by id DESC, filtered by published:
CREATE INDEX idx_posts_published_id ON posts (published, id DESC);
```

### Composite cursor with tiebreaker

```sql
-- Notifications by (created_at DESC, id DESC) per user, only unread:
CREATE INDEX idx_notif_user_unread
    ON notifications (user_id, created_at DESC, id DESC)
    WHERE seen = false;
```

### Index column order matters

Match the index left-to-right with: `equality filters` → `range/cursor columns`:

```sql
-- For WHERE user_id = ? AND tenant_id = ? AND id < ? ORDER BY id DESC
CREATE INDEX idx_x ON table (user_id, tenant_id, id DESC);
--                            ^^^^^^^^^^^^^^^^   ^^^^^^^
--                            equality           cursor range
```

### Verify with EXPLAIN

```sql
EXPLAIN ANALYZE
SELECT ... FROM posts
WHERE published = true AND id < 981
ORDER BY id DESC
LIMIT 21;
-- Should show: Index Scan using idx_posts_published_id
-- Cost should be roughly constant regardless of dataset size.
```

If you see `Seq Scan` or `Sort` in the plan, the index is wrong.

---

## Migration from offset to cursor

Existing offset-paginated endpoint:

```php
// OLD — offset
$result = Paginator::repo(
    UserRepository::instance()->where(Qb::eq('active', true))->orderBy('id DESC'),
    size: 20,
    offset: 40,
);
```

Migrated to cursor:

```php
// NEW — cursor
$result = Paginator::cursor(
    UserRepository::instance()->where(Qb::eq('active', true)),  // ← remove orderBy
    size: 20,
    key: new CursorKey('id', Sort::Desc),                        // ← move ORDER here
    cursor: $req->query('cursor'),                               // ← cursor instead of page/offset
);
```

**Migration checklist**:

1. **Front-end UX**: cursor doesn't support "Page 5 of 12" or arbitrary jumps.
   If your UI requires these — keep offset. If "Load more" / infinite scroll
   is acceptable — migrate.
2. **Remove `->orderBy()`** from the repo — paginator owns ORDER BY now.
3. **Replace `?page=N&size=M`** with `?size=M&cursor=X` in the API contract.
4. **Drop `total` from response** (or document it as not available). Cursor
   intentionally skips `COUNT`.
5. **Add index** matching `(filter_cols, cursor_col DESC)` — see above.
6. **Versioned rollout** — new endpoint at `/v2/...` while keeping `/v1/...`
   offset-based, so old mobile clients keep working until update.

---

## Pitfalls and gotchas

### Don't filter on the cursor column outside the paginator

Bad:
```php
$repo->where(Qb::lt('id', $someBound));   // ← clashes with cursor WHERE
Paginator::cursor($repo, size: 20, key: new CursorKey('id'));
```

The paginator's `andWhere(cursor predicate)` combines with the existing
WHERE — but if the existing WHERE happens to reference the same column,
the result is sensible but probably not what you intended (the bound limits
the whole dataset, not just navigation).

Use cursor for navigation; use WHERE for filtering. Don't overlap them on
the same column.

### Don't include the cursor column in `select()` with an alias mismatch

If you write `SELECT p.name AS product_name`, the hydrated entity has
`$row->product_name`. Cursor needs the value back out — without `alias:
'product_name'` it will fail with `LogicException`.

Either:
- Drop the AS so column matches property: `SELECT p.name`
- Or set `alias:` explicitly on the `CursorKey`

### Don't change `CursorKey` shape mid-deploy

Adding a tiebreaker, changing a column, or flipping a direction changes the
key signature. All client-held cursors become invalid (HTTP 400 on next
request). For production rollouts:

- Roll out the new `CursorKey` at a new API version (`/v2/...`)
- Or accept that clients will see a 400 once and recover by re-fetching from
  the start

### Cursors are not stable across query changes

`Paginator::cursor()` includes signature of `CursorKey`, not signature of the
**full query** (WHERE/JOIN/GROUP). If the user navigates with a cursor and
the front-end changes the filter at the same time, the cursor is still
valid (signature matches) but the rows it points to may now be outside the
new filter.

This is usually fine — the navigation will skip to the next matching row
or return empty data. But if it surprises users, design the front-end to
drop cursors whenever filters change.

### Don't paginate over write-heavy non-monotonic data without a tiebreaker

If you order by `score DESC` (a value that updates in place, not append-only),
cursor pagination can skip or repeat rows when scores change mid-pagination.
Add `id DESC` tiebreaker, but also consider whether cursor is even right for
the use case — for leaderboards, offset may actually be safer.

---

## Quick reference — checklist for production cursor endpoint

- [ ] Cap `$size` (e.g., `min(req->size, 100)`) — don't let clients ask for 10k rows
- [ ] Use composite `CursorKey` with `id` tiebreaker if the primary column has any chance of duplicates
- [ ] Cursor column(s) are in `SELECT` with matching `alias` on `CursorKey`
- [ ] No `->orderBy()` on the input repo — paginator owns it
- [ ] Index covers `(filter_eq_cols..., cursor_col DESC, tiebreaker DESC)`
- [ ] Catch `InvalidCursorException` → return HTTP 400 with `{"error": "invalid_cursor"}`
- [ ] Catch `ValueError` → HTTP 400 for invalid `$size`
- [ ] Set cache headers if the underlying data tolerates it
- [ ] Document for clients: "use cursor opaque, do not parse"
