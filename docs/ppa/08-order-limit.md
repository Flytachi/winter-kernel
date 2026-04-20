# ORDER BY, LIMIT, OFFSET, FOR

---

## orderBy()

```php
final public function orderBy(string $context): static
```

Sets the `ORDER BY` clause. Pass any valid SQL order expression.
Passing an empty string is a **no-op**.

```php
// Single column:
UserRepository::instance()->orderBy('name ASC')->buildSql();
// ORDER BY name ASC

// Multiple columns:
UserRepository::instance()->orderBy('status ASC, created_at DESC')->buildSql();
// ORDER BY status ASC, created_at DESC

// Expression:
UserRepository::instance()->orderBy('LOWER(name) ASC')->buildSql();
// ORDER BY LOWER(name) ASC

// With alias:
UserRepository::instance('u')->orderBy('u.score DESC, u.name ASC')->buildSql();
// ORDER BY u.score DESC, u.name ASC
```

---

## limit()

```php
final public function limit(int $limit, int $offset = 0): static
```

Sets `LIMIT` and optionally `OFFSET`. Both values are validated:

- `$limit` must be ≥ 1 — throws `\TypeError('limit < 1')` otherwise.
- `$offset` must be ≥ 0 — throws `\TypeError('offset < 0')` otherwise.
- `OFFSET 0` is **omitted** from the SQL (no unnecessary clause).

```php
// LIMIT only:
UserRepository::instance()->limit(10)->buildSql();
// … LIMIT 10

// LIMIT + OFFSET:
UserRepository::instance()->limit(10, 30)->buildSql();
// … LIMIT 10 OFFSET 30

// Page helper pattern:
function paginate(int $page, int $perPage = 20): array
{
    return UserRepository::instance()
        ->orderBy('id ASC')
        ->limit($perPage, ($page - 1) * $perPage)
        ->findAll();
}
// page 1 → LIMIT 20 OFFSET 0   (OFFSET omitted)
// page 2 → LIMIT 20 OFFSET 20
// page 3 → LIMIT 20 OFFSET 40
```

`find()` and `findColumn()` call `limit(1)` internally — you never need to set
`LIMIT 1` manually when using those methods.

---

## forBy()

```php
final public function forBy(string $context): static
```

Appends a `FOR` locking clause at the end of the query. Commonly used for
pessimistic locking in transactions.

```php
// PostgreSQL / MySQL — lock selected rows for update:
UserRepository::instance()
    ->where(Qb::eq('id', 42))
    ->forBy('UPDATE')
    ->buildSql();
// SELECT … FROM users WHERE id = :iqb0 FOR UPDATE

// PostgreSQL — shared lock:
UserRepository::instance()
    ->where(Qb::in('id', [1, 2, 3]))
    ->forBy('SHARE')
    ->buildSql();
// SELECT … WHERE id IN (…) FOR SHARE

// PostgreSQL — skip locked (non-blocking):
UserRepository::instance()
    ->where(Qb::eq('status', 'pending'))
    ->limit(1)
    ->forBy('UPDATE SKIP LOCKED')
    ->buildSql();
// … LIMIT 1 FOR UPDATE SKIP LOCKED
```

---

## Typical pagination pattern

```php
function getPage(int $page, int $perPage, ?string $status = null): array
{
    $repo = UserRepository::instance();

    if ($status !== null) {
        $repo->where(Qb::eq('status', $status));
    }

    return $repo
        ->orderBy('created_at DESC')
        ->limit($perPage, ($page - 1) * $perPage)
        ->findAll();
}
```
