# WITH — Common Table Expressions (CTEs)

CTEs let you name a subquery and reference it by name in the main query.
PPA prepends `WITH …` automatically when `with()` or `withRecursive()` is called.

---

## with()

```php
final public function with(
    string              $name,
    RepositoryInterface $repository,
    ?string             $modifier = null
): static
```

Adds a CTE named `$name` containing the SQL from `$repository`.
Multiple `with()` calls append CTEs separated by commas.

```php
// Basic CTE:
$activeCte = UserRepository::instance()
    ->where(Qb::eq('status', 'active'));

$result = UserRepository::instance()
    ->with('active_users', $activeCte)
    ->from('active_users')
    ->orderBy('name ASC')
    ->findAll();
```

Generated SQL:
```sql
WITH active_users AS (SELECT id, name, email, status FROM users WHERE status = :iqb0)
SELECT id, name, email, status FROM active_users
ORDER BY name ASC
```

---

## with() — $modifier

The optional `$modifier` is placed between `AS` and the opening parenthesis.
Supported values depend on the database driver:

| Modifier | Meaning | DB support |
|----------|---------|-----------|
| `'MATERIALIZED'` | Force CTE to be evaluated once and cached | PostgreSQL 12+ |
| `'NOT MATERIALIZED'` | Allow planner to inline the CTE | PostgreSQL 12+ |

```php
$repo->with('expensive_cte', $subquery, 'MATERIALIZED');
// WITH expensive_cte AS MATERIALIZED (SELECT …)
```

---

## Multiple CTEs

```php
$ordersCte = OrderRepository::instance()
    ->select('user_id, COUNT(*) AS order_count, SUM(total) AS revenue')
    ->groupBy('user_id');

$topCte = UserRepository::instance()
    ->with('orders_summary', $ordersCte)
    ->from('orders_summary')
    ->where(Qb::gt('revenue', 1000));

$result = UserRepository::instance()
    ->with('orders_summary', $ordersCte)
    ->with('top_users', $topCte)
    ->from('top_users')
    ->orderBy('revenue DESC')
    ->findAll();
```

Generated SQL:
```sql
WITH orders_summary AS (
  SELECT user_id, COUNT(*) AS order_count, SUM(total) AS revenue
  FROM orders GROUP BY user_id
),
top_users AS (
  WITH orders_summary AS (…) SELECT … FROM orders_summary WHERE revenue > :iqb0
)
SELECT … FROM top_users ORDER BY revenue DESC
```

---

## withRecursive()

```php
final public function withRecursive(
    string              $name,
    RepositoryInterface $repository
): static
```

Same as `with()` but produces `WITH RECURSIVE`. Used for recursive tree or
graph traversal queries.

```php
// Recursive category tree (PostgreSQL / MySQL 8+):
$recursiveCte = CategoryRepository::instance('c')
    ->select('c.id, c.name, c.parent_id, 1 AS depth')
    ->where(Qb::isNull('c.parent_id'))
    ->unionAll(
        CategoryRepository::instance('child')
            ->select('child.id, child.name, child.parent_id, tree.depth + 1')
            ->joinInner('category_tree tree', 'child.parent_id = tree.id')
    );

$result = CategoryRepository::instance()
    ->withRecursive('category_tree', $recursiveCte)
    ->from('category_tree')
    ->orderBy('depth ASC, name ASC')
    ->findAll();
```

Generated SQL:
```sql
WITH RECURSIVE category_tree AS (
  SELECT c.id, c.name, c.parent_id, 1 AS depth
  FROM categories c WHERE c.parent_id IS NULL
  UNION ALL
  SELECT child.id, child.name, child.parent_id, tree.depth + 1
  FROM categories child
  INNER JOIN category_tree tree ON(child.parent_id = tree.id)
)
SELECT id, name, parent_id, depth FROM category_tree
ORDER BY depth ASC, name ASC
```

---

## Binds

Binds from CTE subqueries are automatically merged into the parent query.
No manual `binding()` calls are needed.
