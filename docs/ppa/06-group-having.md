# GROUP BY and HAVING

---

## groupBy()

```php
final public function groupBy(string $context): static
```

Sets the `GROUP BY` clause. Pass a comma-separated list of columns or expressions.
Passing an empty string is a **no-op**.

```php
OrderRepository::instance()
    ->select('status, COUNT(*) AS cnt')
    ->groupBy('status')
    ->buildSql();
// SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status

// Multiple columns:
OrderRepository::instance()
    ->select('user_id, status, COUNT(*) AS cnt, SUM(total) AS revenue')
    ->groupBy('user_id, status')
    ->buildSql();
// SELECT … FROM orders GROUP BY user_id, status

// With alias:
OrderRepository::instance('o')
    ->select('o.user_id, DATE(o.created_at) AS day, SUM(o.total) AS daily_total')
    ->groupBy('o.user_id, DATE(o.created_at)')
    ->buildSql();
```

---

## having()

```php
final public function having(string $context): static
```

Sets the `HAVING` clause. Used to filter groups produced by `GROUP BY`.
Accepts any raw SQL expression. Passing an empty string is a **no-op**.

```php
OrderRepository::instance()
    ->select('user_id, COUNT(*) AS order_count')
    ->groupBy('user_id')
    ->having('COUNT(*) >= 5')
    ->buildSql();
// SELECT user_id, COUNT(*) AS order_count
// FROM orders
// GROUP BY user_id
// HAVING COUNT(*) >= 5

// Using a named alias in HAVING:
OrderRepository::instance()
    ->select('user_id, SUM(total) AS revenue')
    ->groupBy('user_id')
    ->having('SUM(total) > 1000')
    ->orderBy('revenue DESC')
    ->buildSql();
```

---

## Combined example — top customers

```php
$topCustomers = OrderRepository::instance('o')
    ->joinLeft('users u', 'o.user_id = u.id')
    ->select('u.id, u.name, COUNT(*) AS orders, SUM(o.total) AS spent')
    ->where(Qb::eq('o.status', 'completed'))
    ->groupBy('u.id, u.name')
    ->having('SUM(o.total) > 500')
    ->orderBy('spent DESC')
    ->limit(10)
    ->findAll();
```

Generated SQL:
```sql
SELECT u.id, u.name, COUNT(*) AS orders, SUM(o.total) AS spent
FROM orders o
LEFT JOIN users u ON(o.user_id = u.id)
WHERE o.status = :iqb0
GROUP BY u.id, u.name
HAVING SUM(o.total) > 500
ORDER BY spent DESC
LIMIT 10
```
