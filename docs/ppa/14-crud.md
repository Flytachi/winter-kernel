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

Inserts a single record and returns the generated primary key, or
`null` when there's no auto-generated id to report (e.g. a MySQL
INSERT with an explicit value for the PK column, or a table without
AUTO_INCREMENT). Genuine SQL errors surface as `RepositoryException`.

- `null` values are excluded from the INSERT (database handles defaults).
- The primary key column is assumed to be the **first key** of `$entity`.
- PostgreSQL: uses `INSERT … RETURNING <pk>` — returns the inserted PK.
- MariaDB ≥ 10.5: also uses `RETURNING <pk>` (driver detected from
  `PDO::ATTR_SERVER_VERSION`).
- MySQL: uses `PDO::lastInsertId()` — returns the auto-generated id, or
  `null` when no AUTO_INCREMENT id was produced (the row is still
  inserted; the caller already knows the explicit id it supplied).

Requires `winter-cdo` ≥ 3.0.7 — earlier versions threw `CDOException`
on a `lastInsertId() === "0"` result regardless of whether the INSERT
itself succeeded.

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

## insertBatch()

```php
public function insertBatch(iterable|object ...$entities): void
```

Inserts many records, sent to the database in batches so no single statement
exceeds the driver's placeholder limit.

```php
$repo = new UserRepository();

$repo->insertBatch(
    ['name' => 'Alice', 'email' => 'a@example.com', 'status' => 'active'],
    ['name' => 'Bob',   'email' => 'b@example.com', 'status' => 'trial'],
    ['name' => 'Carol', 'email' => 'c@example.com', 'status' => 'active'],
);

// Or spread an array:
$repo->insertBatch(...$usersArray);
```

### One rule: an array is a row, a stream is many

Every argument is read the same way — **an array is one row, anything traversable
is a stream of rows** — so the forms combine freely:

```php
$repo->insertBatch($user1, $user2);       // entities
$repo->insertBatch(['name' => 'John']);   // an array is one row
$repo->insertBatch(...$entities);         // an unpacked array
$repo->insertBatch($generator);           // a stream
$repo->insertBatch($fromCsv, $extraRow);  // mixed, one call
```

Nothing is ambiguous: an array is a valid entity here, and a `Traversable` never is.

### Streaming: why the shape matters

Rows reach the driver lazily and each batch is flushed as it fills, so **peak
memory follows the batch size, not the size of the job**:

```php
// 500 000 rows, ~4 MiB peak — the collection never exists at once
$repo->insertBatch((function () {
    for ($i = 1; $i <= 500_000; $i++) {
        $row = new Bench();
        $row->field      = "Record #{$i}";
        $row->created_at = TimeTool::now()->format('Y-m-d H:i:s');
        yield $row;
    }
})());
```

Building the collection first costs whatever that collection costs. Measured on
500 000 entities: **440 MiB** as an array against **4 MiB** streamed — and most of
that 440 was not the entities but their conversion to rows, which used to happen
for all of them before the first statement was sent.

That measurement is also how a worker dies: PHP's default `memory_limit` is 128 MiB,
per worker, and the array form crosses it around 150 000 rows.

### Failure

Batches are sent as they fill, so a failure part-way leaves the earlier batches
committed. Wrap the call in a transaction when the whole job must be
all-or-nothing.

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

## upsertBatch()

```php
public function upsertBatch(
    iterable  $entities,
    array     $conflictColumns,
    ?array    $updateColumns = null
): void
```

Batch version of `upsert()`. `$entities` is any `iterable` — an array, a generator,
any `Traversable` — and a generator keeps peak memory at one batch whatever the
total, exactly as in [`insertBatch()`](#insertbatch).

```php
$repo = new ProductRepository();

$repo->upsertBatch(
    $stockItems,
    ['sku'],
    [
        'price'      => ':new',
        'stock'      => ':current + :new',
        'updated_at' => 'NOW()',
    ]
);
```

`$updateColumns` maps **column => expression**, not a list of column names:

```php
['price' => ':new']       // ✅ replace price with the incoming value
['price', 'stock']        // ❌ RepositoryException — this is Laravel's shape
[]  or  null              // ✅ ignore conflicts (DO NOTHING / INSERT IGNORE)
```

The list form is refused with a message naming the column and showing the corrected
call, rather than reaching the database and coming back as `no such column: 0`.

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
