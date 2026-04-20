# Declaration — Schema Structure Registry

`Declaration` and `DeclarationItem` are helpers for registering database table
structures (DDL metadata) associated with specific database configurations.
They are used by the Winter framework's migration and schema management tools.

---

## Declaration

```php
final class Declaration
```

A registry that groups `Table` structure objects by `DbConfigInterface` class.
Multiple registrations for the same config class are merged into one
`DeclarationItem`.

### push()

```php
public function push(DbConfigInterface $config, Table $structureTable): void
```

Registers a `Table` structure under the given database config. If an entry for
the same config class already exists, the table is appended to it.

```php
$declaration = new Declaration();

$usersTable  = new Table('users',  [$idCol, $nameCol, $emailCol]);
$ordersTable = new Table('orders', [$idCol, $userIdCol, $totalCol]);

$dbConfig = new DbConfig();

// Both tables go to the same DbConfig → single DeclarationItem:
$declaration->push($dbConfig, $usersTable);
$declaration->push($dbConfig, $ordersTable);

// Different config → separate DeclarationItem:
$archiveConfig = new ArchiveDbConfig();
$declaration->push($archiveConfig, new Table('archive_users', [...]));

count($declaration->getItems()); // 2
```

### getItems()

```php
public function getItems(): array  // DeclarationItem[]
```

Returns all registered `DeclarationItem` objects.

---

## DeclarationItem

```php
final class DeclarationItem
```

Holds all table structures associated with a single database configuration.

### Properties

```php
public readonly DbConfigInterface $config;
```

The database config instance this item belongs to.

### push()

```php
public function push(Table $newTable): void
```

Appends a `Table` structure to this item.

### getTables()

```php
public function getTables(): array  // Table[]
```

Returns all registered `Table` objects in insertion order.

---

## Usage in schema management

```php
// Register structures:
$declaration = new Declaration();
$declaration->push(new AppDbConfig(), UserRepository::getTableStructure());
$declaration->push(new AppDbConfig(), OrderRepository::getTableStructure());

// Process — e.g. generate DDL:
foreach ($declaration->getItems() as $item) {
    $db = $item->config;
    foreach ($item->getTables() as $table) {
        echo $table->toSql('pgsql') . "\n";
    }
}
```

---

## Config class matching

`Declaration` compares configs by **class name** (`::class`), not by instance
identity. Two separate instances of the same config class are treated as the
same config:

```php
$declaration->push(new DbConfig(), $table1);
$declaration->push(new DbConfig(), $table2); // new instance, same class

count($declaration->getItems()); // 1 — merged into one item
```
