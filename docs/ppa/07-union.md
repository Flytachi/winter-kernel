# UNION and UNION ALL

Combine the results of two or more repository queries.

---

## union()

```php
final public function union(RepositoryInterface $repository): static
```

Appends `UNION <subquery>` after the main query body. Duplicate rows are
removed (standard SQL `UNION` behaviour).

```php
$activeUsers = UserRepository::instance()
    ->where(Qb::eq('status', 'active'));

$trialUsers = UserRepository::instance()
    ->where(Qb::eq('status', 'trial'));

$result = UserRepository::instance()
    ->where(Qb::eq('status', 'premium'))
    ->union($activeUsers)
    ->union($trialUsers)
    ->orderBy('name ASC')
    ->findAll();
```

Generated SQL:
```sql
SELECT id, name, email, status FROM users WHERE status = :iqb0
UNION SELECT id, name, email, status FROM users WHERE status = :iqb1
UNION SELECT id, name, email, status FROM users WHERE status = :iqb2
ORDER BY name ASC
```

---

## unionAll()

```php
final public function unionAll(RepositoryInterface $repository): static
```

Same as `union()` but preserves duplicates (`UNION ALL`). More efficient when
you know the result sets do not overlap.

```php
$currentMonth = OrderRepository::instance()
    ->select('user_id, total, created_at')
    ->where(Qb::gte('created_at', '2024-06-01'));

$lastMonth = OrderRepository::instance()
    ->select('user_id, total, created_at')
    ->where(Qb::and(
        Qb::gte('created_at', '2024-05-01'),
        Qb::lt('created_at',  '2024-06-01'),
    ));

$combined = OrderRepository::instance()
    ->select('user_id, total, created_at')
    ->where(Qb::gte('created_at', '2024-04-01'))
    ->where(Qb::lt('created_at', '2024-05-01'))
    ->unionAll($currentMonth)
    ->unionAll($lastMonth)
    ->orderBy('created_at DESC')
    ->findAll();
```

---

## Chaining multiple UNIONs

`union()` and `unionAll()` can be chained freely. Each call appends to the
union buffer:

```php
$query
    ->union($part1)
    ->unionAll($part2)
    ->union($part3);
// … UNION … UNION ALL … UNION …
```

---

## Binds

Binds from all union parts are merged automatically into the parent query.
You do not need to call `binding()` manually.

---

## Notes

- `ORDER BY` and `LIMIT` on the outer query apply to the entire result of the union.
- Column lists in each part must match (same count and compatible types).
- `select()` is not enforced — ensure all parts return the same columns manually.
