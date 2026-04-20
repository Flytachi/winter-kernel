# View — Fetch Methods

All fetch methods are provided by `RepositoryViewTrait` and available on
`Repository`, `RepositoryView`, and `Repo`.

Every fetch method:
1. Calls `buildSql()` to get the query string.
2. Prepares it via `$this->db()->prepare()`.
3. Binds accumulated parameters via `useBind()`.
4. Executes the statement.
5. Calls `cleanCache()` to reset the query builder state.

---

## find()

```php
final public function find(?string $entityClassName = null): ?object
```

Executes the query with `LIMIT 1` and returns the first row as an object,
or `null` if no row matches.

```php
$user = UserRepository::instance()
    ->where(Qb::eq('email', 'alice@example.com'))
    ->find();
// object(UserEntity) or null

// Override entity class for this call only:
$raw = UserRepository::instance()
    ->where(Qb::eq('id', 1))
    ->find(\stdClass::class);
// object(stdClass)
```

---

## findAll()

```php
final public function findAll(?string $entityClassName = null): array
```

Executes the query and returns all matching rows as an array of objects.
Returns an empty array when no rows match.

```php
$users = UserRepository::instance()
    ->where(Qb::eq('status', 'active'))
    ->orderBy('name ASC')
    ->limit(50)
    ->findAll();
// array<UserEntity>

// Override entity class:
$rows = UserRepository::instance()->findAll(\stdClass::class);
```

---

## findColumn()

```php
final public function findColumn(int $column = 0): mixed
```

Executes the query with `LIMIT 1` and returns a single column value.
The `$column` parameter is the zero-based column index.
Returns `false` if no row is found.

```php
// Count:
$count = UserRepository::instance()
    ->select('COUNT(*)')
    ->findColumn();
// int or string depending on driver

// First email:
$email = UserRepository::instance()
    ->where(Qb::eq('id', 42))
    ->select('email')
    ->findColumn(0);
// 'alice@example.com' or false
```

---

## count()

```php
final public function count(): int
```

Wraps the current `SELECT` expression (or `*`) in `COUNT(…)` and returns the
integer result.

```php
// Total active users:
$n = UserRepository::instance()
    ->where(Qb::eq('status', 'active'))
    ->count();
// SELECT COUNT(*) FROM users WHERE status = :iqb0

// Count distinct statuses:
$n = UserRepository::instance()
    ->select('DISTINCT status')
    ->count();
// SELECT COUNT(DISTINCT status) FROM users
```

---

## exists()

```php
final public function exists(): bool
```

Returns `true` if at least one row matches, `false` otherwise.
Uses `SELECT 1 LIMIT 1` internally — more efficient than `count() > 0`.

```php
$exists = UserRepository::instance()
    ->where(Qb::eq('email', 'alice@example.com'))
    ->exists();
// bool

if (!UserRepository::instance()->where(Qb::eq('slug', $slug))->exists()) {
    throw new \RuntimeException('User not found');
}
```

---

## rawFetch()

```php
final public function rawFetch(
    string $sql,
    array  $binds = [],
    ?string $entityClassName = null
): array
```

Executes a raw SQL string with explicit `CDOBind` objects and returns an array
of hydrated objects. Does **not** use the query builder state.

```php
use Flytachi\Winter\Cdo\CDOBind;

$results = (new UserRepository())->rawFetch(
    'SELECT u.id, u.name, COUNT(o.id) AS order_count
     FROM users u
     LEFT JOIN orders o ON u.id = o.user_id
     WHERE u.status = :status
     GROUP BY u.id, u.name
     HAVING COUNT(o.id) > :min_orders',
    [
        new CDOBind(':status',     'active'),
        new CDOBind(':min_orders', 3),
    ]
);
// array<UserEntity>
```

Use `rawFetch()` for queries that are too complex or non-standard for the
query builder.

---

## Entity hydration

All fetch methods hydrate rows using `PDO::FETCH_CLASS` with the repository's
configured `$entityClassName` (or the `$entityClassName` argument if provided).
Public properties of the entity class are filled from the result set columns.
