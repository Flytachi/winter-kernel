# Advanced Examples

Real-world query patterns combining multiple PPA features.

---

## 1. Paginated list with filters

```php
function getUserPage(
    int     $page,
    int     $perPage   = 20,
    ?string $status    = null,
    ?string $role      = null,
    ?string $search    = null,
    string  $sortBy    = 'created_at',
    string  $sortDir   = 'DESC'
): array {
    $repo = UserRepository::instance('u');

    if ($status !== null) {
        $repo->andWhere(Qb::eq('u.status', $status));
    }
    if ($role !== null) {
        $repo->andWhere(Qb::eq('u.role', $role));
    }
    if ($search !== null) {
        $repo->andWhere(Qb::or(
            Qb::like('u.name', "%{$search}%"),
            Qb::like('u.email', "%{$search}%"),
        ));
    }

    return $repo
        ->orderBy("u.{$sortBy} {$sortDir}")
        ->limit($perPage, ($page - 1) * $perPage)
        ->findAll();
}
```

---

## 2. Multi-join reporting query

```php
$report = OrderRepository::instance('o')
    ->joinLeft('users u',      'o.user_id = u.id')
    ->joinLeft('products p',   'o.product_id = p.id')
    ->joinLeft('categories c', 'p.category_id = c.id')
    ->select(
        'u.name AS customer,
         p.title AS product,
         c.name AS category,
         o.total,
         o.status,
         o.created_at'
    )
    ->where(Qb::and(
        Qb::gte('o.created_at', '2024-01-01'),
        Qb::in('o.status', ['completed', 'refunded']),
    ))
    ->orderBy('o.created_at DESC')
    ->limit(500)
    ->findAll();
```

---

## 3. Subquery in FROM

```php
// Rank users by total order revenue inside a subquery,
// then filter on rank in the outer query.

$ranked = OrderRepository::instance('o')
    ->joinLeft('users u', 'o.user_id = u.id')
    ->select(
        'u.id,
         u.name,
         SUM(o.total) AS revenue,
         RANK() OVER (ORDER BY SUM(o.total) DESC) AS rnk'
    )
    ->where(Qb::eq('o.status', 'completed'))
    ->groupBy('u.id, u.name');

$top10 = UserRepository::instance()
    ->as('ranked')
    ->from($ranked)
    ->where(Qb::lte('rnk', 10))
    ->orderBy('rnk ASC')
    ->findAll();
```

---

## 4. CTE — recursive category tree

```php
// Fetch a full category hierarchy starting from root nodes.

$recursivePart = CategoryRepository::instance('c')
    ->select('c.id, c.name, c.parent_id, 1 AS depth')
    ->where(Qb::isNull('c.parent_id'))
    ->unionAll(
        CategoryRepository::instance('child')
            ->select('child.id, child.name, child.parent_id, t.depth + 1')
            ->joinInner('category_tree t', 'child.parent_id = t.id')
    );

$tree = CategoryRepository::instance()
    ->withRecursive('category_tree', $recursivePart)
    ->from('category_tree')
    ->orderBy('depth ASC, name ASC')
    ->findAll();
```

---

## 5. CTE — top-N per group

```php
// For each category, get the 3 best-selling products.

$rankedProducts = ProductRepository::instance('p')
    ->joinLeft('order_items oi', 'p.id = oi.product_id')
    ->select(
        'p.id, p.title, p.category_id,
         SUM(oi.quantity) AS sold,
         ROW_NUMBER() OVER (PARTITION BY p.category_id ORDER BY SUM(oi.quantity) DESC) AS rn'
    )
    ->groupBy('p.id, p.title, p.category_id');

$top3 = ProductRepository::instance()
    ->with('ranked', $rankedProducts)
    ->from('ranked')
    ->where(Qb::lte('rn', 3))
    ->orderBy('category_id ASC, rn ASC')
    ->findAll();
```

---

## 6. UNION — multi-status feed

```php
// Combine urgent, normal, and low-priority tasks into one ordered feed.

$urgent = TaskRepository::instance()
    ->where(Qb::eq('priority', 'urgent'))
    ->select('id, title, priority, due_at');

$normal = TaskRepository::instance()
    ->where(Qb::eq('priority', 'normal'))
    ->select('id, title, priority, due_at');

$feed = TaskRepository::instance()
    ->where(Qb::eq('priority', 'low'))
    ->select('id, title, priority, due_at')
    ->union($urgent)
    ->union($normal)
    ->orderBy('due_at ASC')
    ->limit(100)
    ->findAll();
```

---

## 7. Pessimistic locking in a transaction

```php
$repo = new UserRepository();
$cdo  = $repo->db();

$cdo->beginTransaction();
try {
    $user = UserRepository::instance()
        ->where(Qb::eq('id', $userId))
        ->forBy('UPDATE')
        ->find();

    if ($user === null || $user->balance < $amount) {
        $cdo->rollBack();
        return false;
    }

    $repo->update(
        ['balance' => $user->balance - $amount],
        Qb::eq('id', $userId)
    );

    $cdo->commit();
    return true;
} catch (\Throwable $e) {
    $cdo->rollBack();
    throw $e;
}
```

---

## 8. Upsert bulk inventory sync

```php
// Sync stock levels — accumulate quantity, always overwrite cost.

$repo = new ProductRepository();
$repo->upsertGroup(
    $incomingStock,         // array of ['sku', 'quantity', 'cost']
    ['sku'],
    [
        'quantity'   => ':current + :new',
        'cost'       => ':new',
        'updated_at' => 'NOW()',
    ]
);
```

---

## 9. Ad-hoc query with CteRepo

```php
use Flytachi\Winter\Kernel\Ppa\Stereotype\CteRepo;

// Raw aggregation across multiple tables — no dedicated repository needed.
$stats = (new CteRepo(AppDbConfig::class))
    ->from('orders o')
    ->joinLeft('users u', 'o.user_id = u.id')
    ->select(
        'DATE(o.created_at) AS day,
         COUNT(*) AS order_count,
         SUM(o.total) AS revenue,
         COUNT(DISTINCT o.user_id) AS unique_buyers'
    )
    ->where(Qb::gte('o.created_at', '2024-01-01'))
    ->groupBy('DATE(o.created_at)')
    ->orderBy('day ASC')
    ->findAll();
```

---

## 10. rawFetch — complex native SQL

```php
use Flytachi\Winter\Cdo\CDOBind;

// Recursive neighbour search using a DB-specific function.
$nearby = (new LocationRepository())->rawFetch(
    "SELECT id, name, ST_Distance(geom, ST_MakePoint(:lng, :lat)) AS dist
     FROM locations
     WHERE ST_DWithin(geom, ST_MakePoint(:lng, :lat)::geography, :radius)
     ORDER BY dist ASC
     LIMIT 20",
    [
        new CDOBind(':lng',    $longitude),
        new CDOBind(':lat',    $latitude),
        new CDOBind(':radius', 5000),
    ]
);
```
