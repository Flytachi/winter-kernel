# CRUD — Write Operations

Write operations are provided by `RepositoryCrudTrait` and available on
`Repository` and `RepositoryCrud`. Each method wraps the corresponding `CDO`
method, mapping `CDOException` to `RepositoryException`.

All methods call `originTable()` internally — they always target the repository's
configured table.

---

## insert()

```php
public function insert(object|array $entity): mixed
```

Inserts a single record and returns the generated primary key.

- `null` values are excluded from the INSERT (database handles defaults).
- The primary key column is assumed to be the **first key** of `$entity`.
- PostgreSQL: uses `RETURNING <pk>`.
- MySQL/MariaDB: uses `PDO::lastInsertId()`.

```php
$repo = new UserRepository();

// Array form:
$id = $repo->insert([
    'id'     => null,
    'name'   => 'Alice',
    'email'  => 'alice@example.com',
    'status' => 'active',
]);
// SQL: INSERT INTO users (name, email, status) VALUES (:name, :email, :status)

// Object form:
$user         = new UserEntity();
$user->id     = null;
$user->name   = 'Bob';
$user->email  = 'bob@example.com';
$user->status = 'trial';
$id = $repo->insert($user);
```

---

## insertGroup()

```php
public function insertGroup(array|object ...$entities): void
```

Batch-inserts multiple records efficiently. Rows are chunked to avoid
exceeding database placeholder limits.

```php
$repo = new UserRepository();

$repo->insertGroup(
    ['name' => 'Alice', 'email' => 'a@example.com', 'status' => 'active'],
    ['name' => 'Bob',   'email' => 'b@example.com', 'status' => 'trial'],
    ['name' => 'Carol', 'email' => 'c@example.com', 'status' => 'active'],
);

// Or spread an array:
$repo->insertGroup(...$usersArray);
```

---

## update()

```php
public function update(object|array $entity, Qb $qb): int|string
```

Updates all rows matching `$qb` and returns the number of affected rows.
A `Qb` condition is always required — there is no "update all" variant.

```php
$repo = new UserRepository();

// Update one field:
$affected = $repo->update(
    ['status' => 'inactive'],
    Qb::eq('id', 42)
);

// Update multiple fields with a compound condition:
$affected = $repo->update(
    ['status' => 'inactive', 'updated_at' => date('Y-m-d H:i:s')],
    Qb::and(
        Qb::lt('last_login', '2024-01-01'),
        Qb::eq('status', 'active'),
    )
);
```

---

## delete()

```php
public function delete(Qb $qb): int|string
```

Deletes all rows matching `$qb` and returns the number deleted.
A condition is always required.

```php
$repo = new UserRepository();

// Delete by ID:
$deleted = $repo->delete(Qb::eq('id', 42));

// Bulk soft-delete cleanup:
$deleted = $repo->delete(
    Qb::and(
        Qb::isNotNull('deleted_at'),
        Qb::lt('deleted_at', date('Y-m-d', strtotime('-90 days'))),
    )
);
```

---

## upsert()

```php
public function upsert(
    object|array  $entity,
    array         $conflictColumns,
    ?array        $updateColumns = null
): mixed
```

Inserts a record; if the conflict target is violated, updates the existing row.

- `$conflictColumns` defines the uniqueness constraint (conflict target).
- `$updateColumns` defines what to update; `null` means "do nothing" (ignore).
- Use `:new` and `:current` tokens in update expressions — see the
  [CDO docs](https://winterframe.net/docs/cdo) for token reference.

```php
$repo = new UserRepository();

// Insert or update email/status on email conflict:
$repo->upsert(
    ['email' => 'alice@example.com', 'name' => 'Alice', 'status' => 'active'],
    ['email'],
    ['name' => ':new', 'status' => ':new']
);
// PostgreSQL: ON CONFLICT (email) DO UPDATE SET name = EXCLUDED.name, ...
// MySQL:      ON DUPLICATE KEY UPDATE name = VALUES(name), ...

// Ignore on conflict:
$repo->upsert(
    ['email' => 'alice@example.com', 'name' => 'Alice'],
    ['email']   // $updateColumns = null → DO NOTHING / INSERT IGNORE
);
```

---

## upsertGroup()

```php
public function upsertGroup(
    array   $entities,
    array   $conflictColumns,
    ?array  $updateColumns = null
): void
```

Batch version of `upsert()`. Rows are chunked to avoid database limits.

```php
$repo = new ProductRepository();

$repo->upsertGroup(
    $stockItems,
    ['sku'],
    [
        'price'      => ':new',
        'stock'      => ':current + :new',
        'updated_at' => 'NOW()',
    ]
);
```

---

## Exception handling

All write methods catch `CDOException` and re-throw it as `RepositoryException`
(logged at `ALERT` level). The original exception is attached as the `$previous`
cause and remains accessible via `getPrevious()`.

```php
try {
    $repo->insert($user);
} catch (RepositoryException $e) {
    // $e->getMessage()  — human-readable message
    // $e->getPrevious() — original CDOException
}
```
