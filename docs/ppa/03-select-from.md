# SELECT, FROM, AS

These three methods control the "what" and "where" of a query.

---

## select() — Custom SELECT Expression

```php
final public function select(string $option): static
```

Overrides the automatically built `SELECT` clause with a raw SQL expression.
When `select()` is called, result hydration always uses `\stdClass` regardless
of the configured `$entityClassName`.

```php
// Count with a label:
$repo->select('COUNT(*) AS total');

// Multiple columns:
$repo->select('id, name, email');

// Aggregates:
$repo->select('status, COUNT(*) AS cnt, AVG(score) AS avg_score');

// Subselect expression:
$repo->select('*, (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS order_count');
```

Passing an empty string is a **no-op** — the default `SELECT *` (or entity
column list) is preserved.

---

## from() — Override the FROM Source

```php
final public function from(string|RepositoryInterface $repository): static
```

Overrides the default `FROM originTable()` with a custom source.
Accepts either a plain table name string or a repository instance (subquery).

### String form

```php
$repo->from('users u');
// FROM users u

$repo->from('public.archive_users');
// FROM public.archive_users
```

### Subquery form

Pass a `RepositoryInterface` instance whose built SQL becomes the subquery.
The alias **must** be set first with `as()`, otherwise `RepositoryException` is thrown.
Binds from the subquery are merged automatically.

```php
$sub = UserRepository::instance('inner')
    ->where(Qb::eq('status', 'active'));

$result = UserRepository::instance('outer')
    ->as('outer')
    ->from($sub)
    ->orderBy('outer.name')
    ->findAll();
// FROM (SELECT id, name, email, status FROM users inner WHERE status = :iqb0) outer
// ORDER BY outer.name
```

### Constraints

- `from()` can only be called **once** per query.
  A second call throws `RepositoryException: FROM clause already set`.
- When using a repository subquery, `as()` must be called **before** `from()`.

---

## as() — Table Alias

```php
final public function as(string $alias): static
```

Sets a table alias. It is appended to the `FROM` line and also used as the
column prefix when building the `SELECT` list from an entity class.

```php
UserRepository::instance()
    ->as('u')
    ->buildSql();
// SELECT u.id, u.name, u.email, u.status FROM users u

UserRepository::instance()
    ->as('u')
    ->joinLeft('orders o', 'u.id = o.user_id')
    ->buildSql();
// SELECT u.id, ... FROM users u LEFT JOIN orders o ON(u.id = o.user_id)
```

Passing an empty string is a **no-op**.

`instance()` accepts an alias directly as a shorthand:

```php
UserRepository::instance('u');
// same as (new UserRepository())->as('u')
```

---

## How SELECT is built automatically

When `select()` has **not** been called, PPA builds the column list based on
`$entityClassName`:

| Condition | SELECT clause |
|-----------|--------------|
| `entityClassName` is `\stdClass` or extends it | `SELECT *` |
| `entityClassName` implements `EntityInterface` | Uses `selection()` map + plain names for missing properties |
| Plain PHP class (no `\stdClass`, no `EntityInterface`) | `SELECT alias.prop1, alias.prop2, …` from `get_class_vars()` |

```php
// stdClass (default) → SELECT *
class BasicRepository extends RepositoryView
{
    protected string $dbConfigClassName = DbConfig::class;
    public static string $table = 'logs';
}

// Entity class → explicit column list
class UserRepository extends Repository
{
    protected string $dbConfigClassName = DbConfig::class;
    protected string $entityClassName   = UserEntity::class; // plain class, no stdClass
    public static string $table         = 'users';
}
// → SELECT u.id, u.name, u.email FROM users u   (with alias 'u')
```
