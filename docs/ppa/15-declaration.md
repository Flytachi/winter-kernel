# Declaration — Schema Structure Registry

`Declaration` and `DeclarationItem` are the in-memory model that the
framework's migration tooling (`Db` console command) operates on. A
`DeclarationItem` groups everything that belongs to a single `DbConfig`:
the table structures discovered for it, the `#[Extension]` attributes
declared on it, and the `#[Migratable]` marker that opts it into
migration.

In normal use you don't construct these manually — they're built by
`PPAMapping::scanningDeclaration()` from your `Kernel::$pathRoot`. The
push-style API documented here is the lower-level building block.

> Migration rules (which attributes participate, in what order DDL
> runs, idempotency, priorities) are documented separately in
> [18-migration.md](18-migration.md).

---

## How `PPAMapping` builds a Declaration

```
PPAMapping::scanningDeclaration(?string $rootDir = null)
  ├── Scanner::run($rootDir ?? Kernel::$pathRoot)
  │     └── finds every class that implements RepositoryInterface
  │
  ├── for each Repository class:
  │     ├── instantiate the repository
  │     ├── resolve the DbConfig class via $repository->getDbConfigClassName()
  │     ├── instantiate the DbConfig, call setUp()
  │     ├── require entity to have #[Table] — else skip this repo silently
  │     ├── build Column structures from entity property attributes
  │     │   (ColumnMapping walks getProperties())
  │     └── declaration->push($config, new Structure\Table(...))
  │
  └── returns Declaration (with all per-config items merged)
```

Each `DeclarationItem` then **auto-collects** its config-level
attributes via reflection in its constructor:

- `#[Extension]` (repeatable) → `$item->getExtensions()` returns an
  array of `Structure\Extension` objects, deduplicated by name.
- `#[Migratable]` (single) → `$item->isMigratable()` returns whether the
  config opted in; `$item->getPriority()` returns the declared
  `MigratablePriority` (default `Normal`).

This means the **same** `DeclarationItem` instance carries everything
the `Db` command needs to make migration decisions — no extra lookup
on the config class outside the registry.

---

## `Declaration`

```php
final class Declaration
```

A registry that groups `Structure\Table` objects by `DbConfigInterface`
class. Multiple `push()` calls for the same config class are merged into
one `DeclarationItem`.

### `push()`

```php
public function push(DbConfigInterface $config, Table $structureTable): void
```

Registers a `Table` structure under the given DbConfig. If a
`DeclarationItem` for the same config class already exists, the table is
appended to it. Otherwise a new `DeclarationItem` is created (which
triggers reflection-based auto-collection of `#[Extension]` and
`#[Migratable]` from the config class).

```php
$declaration = new Declaration();

$usersTable  = new Table('users',  [$idCol, $nameCol, $emailCol]);
$ordersTable = new Table('orders', [$idCol, $userIdCol, $totalCol]);

$dbConfig = new AppDbConfig();

// Both tables go to the same DbConfig → single DeclarationItem:
$declaration->push($dbConfig, $usersTable);
$declaration->push($dbConfig, $ordersTable);

// Different config → separate DeclarationItem:
$archiveConfig = new ArchiveDbConfig();
$declaration->push($archiveConfig, new Table('archive_users', [...]));

count($declaration->getItems()); // 2
```

### `getItems()`

```php
public function getItems(): array  // DeclarationItem[]
```

Returns all registered items in insertion order (first-seen config
class first).

### Matching is by class name, not instance

```php
$declaration->push(new AppDbConfig(), $table1);
$declaration->push(new AppDbConfig(), $table2); // new instance, same class

count($declaration->getItems()); // 1 — merged into one item
```

The first config instance pushed for a given class is the one stored on
the resulting `DeclarationItem`; subsequent instances of the same class
are dropped (their tables are merged into the existing item).

---

## `DeclarationItem`

```php
final class DeclarationItem
```

Holds everything associated with a single DbConfig: table structures
registered via `push()` plus `#[Extension]` and `#[Migratable]`
attributes auto-collected at construction time.

### Public read-only state

```php
public readonly DbConfigInterface $config;     // the DbConfig instance
```

### `push()` / `getTables()`

```php
public function push(Table $newTable): void
public function getTables(): array              // Table[]
```

Append / read the tables collected for this config. Tables stay in
insertion order.

### `getExtensions(): array<Structure\Extension>`

Returns the `#[Extension]` attributes read from the config class.
Deduplicated by `name` — if the same extension is declared twice, only
the first declaration is kept.

```php
#[Migratable]
#[Extension('uuid-ossp')]
#[Extension('pgcrypto', cascade: true)]
final class AppDbConfig extends PgDbConfig { /* … */ }

// later:
foreach ($item->getExtensions() as $ext) {
    echo $ext->toSql('pgsql'), PHP_EOL;
}
// CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
// CREATE EXTENSION IF NOT EXISTS "pgcrypto" CASCADE;
```

`Structure\Extension::toSql()` is PostgreSQL-only and throws
`InvalidArgumentException` for any other dialect. The `Db` command
gates emission of extensions on `getDriver() === 'pgsql'` — a
mysql/mariadb config with `#[Extension]` on it is silently skipped at
the SQL-generation step.

### `isMigratable(): bool` / `getPriority(): MigratablePriority`

```php
public function isMigratable(): bool;
public function getPriority(): MigratablePriority;
```

`isMigratable()` is `true` iff the config class carries `#[Migratable]`.
`getPriority()` returns the declared priority (default
`MigratablePriority::Normal` when the attribute is absent — keeps the
return type non-nullable for callers).

The `Db` command uses both to filter and sort:

```php
$items = array_filter($declaration->getItems(), fn ($i) => $i->isMigratable());
usort($items, fn ($a, $b) => $a->getPriority()->value <=> $b->getPriority()->value);
```

---

## End-to-end example

```php
// 1. Build via the scanner (typical):
$declaration = PPAMapping::scanningDeclaration();

// 2. Or build manually for one-off tooling:
$declaration = new Declaration();
$declaration->push(new AppDbConfig(), $usersTable);
$declaration->push(new AppDbConfig(), $ordersTable);

// 3. Process — what the `Db migrate` command does internally:
foreach ($declaration->getItems() as $item) {
    if (!$item->isMigratable()) {
        continue;  // config doesn't carry #[Migratable]
    }

    $driver = $item->config->getDriver();
    $db     = $item->config->connection();

    // Extensions first (pgsql only):
    if ($driver === 'pgsql') {
        foreach ($item->getExtensions() as $ext) {
            $db->exec($ext->toSql('pgsql'));
        }
    }

    // Then tables (CREATE TABLE + external CREATE INDEX / ALTER TABLE):
    foreach ($item->getTables() as $table) {
        $db->exec($table->toSql($driver));
    }
}
```

The real `Db::migrate()` adds per-statement error handling, `EXIST`
badge detection for known SQLSTATE codes, and priority-based item
sorting — see [18-migration.md](18-migration.md) for the full rules.
