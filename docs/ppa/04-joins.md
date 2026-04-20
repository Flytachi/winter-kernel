# JOINs

PPA supports five JOIN types, each accepting either a plain string or a
repository subquery as the joined source. The `$on` condition accepts either a
plain string or a `Qb` instance (parameterised condition). Multiple JOINs on a
single query are concatenated in order.

---

## Method signatures

```php
final public function join(string|RepositoryInterface $repository, string|Qb $on): static
final public function joinInner(string|RepositoryInterface $repository, string|Qb $on): static
final public function joinLeft(string|RepositoryInterface $repository, string|Qb $on): static
final public function joinRight(string|RepositoryInterface $repository, string|Qb $on): static
final public function joinCross(string|RepositoryInterface $repository): static
```

---

## String form — simple table join

Pass the table name (with optional alias) as a string:

```php
UserRepository::instance('u')
    ->joinLeft('orders o', 'u.id = o.user_id')
    ->buildSql();
// SELECT u.id, ... FROM users u LEFT JOIN orders o ON(u.id = o.user_id)

UserRepository::instance('u')
    ->join('roles r', 'u.role_id = r.id')
    ->joinLeft('permissions p', 'r.id = p.role_id')
    ->buildSql();
// … JOIN roles r ON(u.role_id = r.id)
// LEFT JOIN permissions p ON(r.id = p.role_id)
```

---

## Repository form — subquery join

Pass a `RepositoryInterface` instance whose built SQL becomes the subquery.
If the repository has no extra SQL parts (only the default table), the plain
table name is used. If it has any extra parts (where, limit, etc.), it is
wrapped in parentheses as a derived table.

### Simple case (no extra parts → plain table name):

```php
$orders = OrderRepository::instance('o');

UserRepository::instance('u')
    ->joinLeft($orders, 'u.id = o.user_id')
    ->buildSql();
// … LEFT JOIN orders o ON(u.id = o.user_id)
```

### Derived table (with extra SQL parts):

```php
$recentOrders = OrderRepository::instance('o')
    ->where(Qb::gt('o.created_at', '2024-01-01'))
    ->select('user_id, SUM(total) AS total_spent');

UserRepository::instance('u')
    ->joinLeft($recentOrders, 'u.id = o.user_id')
    ->buildSql();
// … LEFT JOIN (SELECT user_id, SUM(total) AS total_spent FROM orders o
//              WHERE o.created_at > :iqb0) o
//   ON(u.id = o.user_id)
```

Binds from the subquery are merged into the parent query automatically.

---

## Qb ON condition — parameterised join

Pass a `Qb` instance as `$on` to use parameterised placeholders in the join
condition. Binds are merged into the parent query automatically.

```php
// Simple parameterised ON
UserRepository::instance('u')
    ->joinLeft('orders o', Qb::eq('u.id', $userId))
    ->buildSql();
// … LEFT JOIN orders o ON(u.id = :iqb0)

// Composite ON condition
UserRepository::instance('u')
    ->joinLeft('orders o', Qb::and(
        Qb::eq('u.id', $userId),
        Qb::eq('o.active', true)
    ))
    ->buildSql();
// … LEFT JOIN orders o ON(u.id = :iqb0 AND o.active = :iqb1)
```

Combining a repository subquery with a `Qb` ON is also supported — binds from
both sources are merged:

```php
$recentOrders = OrderRepository::instance('o')
    ->where(Qb::gt('o.created_at', '2024-01-01'));

UserRepository::instance('u')
    ->joinLeft($recentOrders, Qb::eq('u.id', $userId))
    ->buildSql();
```

---

## CROSS JOIN

`joinCross` takes no `$on` condition — it produces a cartesian product:

```php
UserRepository::instance('u')
    ->joinCross('date_series d')
    ->buildSql();
// SELECT … FROM users u CROSS JOIN date_series d

// Repository form:
$series = DateSeriesRepository::instance('d');
UserRepository::instance('u')
    ->joinCross($series)
    ->buildSql();
// SELECT … FROM users u CROSS JOIN date_series d
```

---

## Multiple JOINs

All five JOIN methods append to the same internal join buffer, so they can be
freely chained in any order:

```php
OrderRepository::instance('o')
    ->joinLeft('users u',    'o.user_id = u.id')
    ->joinLeft('products p', 'o.product_id = p.id')
    ->joinLeft('categories c', 'p.category_id = c.id')
    ->where(Qb::eq('o.status', 'shipped'))
    ->select('o.id, u.name, p.title, c.name AS category')
    ->buildSql();
// SELECT o.id, u.name, p.title, c.name AS category
// FROM orders o
// LEFT JOIN users u ON(o.user_id = u.id)
// LEFT JOIN products p ON(o.product_id = p.id)
// LEFT JOIN categories c ON(p.category_id = c.id)
// WHERE o.status = :iqb0
```

---

## JOIN type summary

| Method | SQL keyword | ON required |
|--------|------------|-------------|
| `join()` | `JOIN` | Yes |
| `joinInner()` | `INNER JOIN` | Yes |
| `joinLeft()` | `LEFT JOIN` | Yes |
| `joinRight()` | `RIGHT JOIN` | Yes |
| `joinCross()` | `CROSS JOIN` | No |
