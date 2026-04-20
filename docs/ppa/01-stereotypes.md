# Stereotypes — Choosing the Right Base Class

A **stereotype** is a concrete abstract base class you extend to create your own repository.
Each one wires together `RepositoryCore` with the appropriate traits.

---

## Overview

| Class | Traits included | Use when |
|-------|----------------|---------|
| `Repository` | CrudTrait + ViewTrait | Need both reads and writes (most common) |
| `RepositoryView` | ViewTrait | Read-only data source (views, reports, projections) |
| `RepositoryCrud` | CrudTrait | Write-only (import pipelines, event sourcing sinks) |
| `CteRepo` | ViewTrait | Ad-hoc query without a fixed table — instantiated directly |

---

## Repository — Full Access

```php
use Flytachi\Winter\K2\Ppa\Stereotype\Repository;

class UserRepository extends Repository
{
    protected string $dbConfigClassName = DbConfig::class;
    protected string $entityClassName   = UserEntity::class;
    public static string $table         = 'users';
}
```

Provides the complete API:
- All query-builder methods (`where`, `join`, `limit`, …)
- Read methods (`find`, `findAll`, `count`, `exists`, …)
- Write methods (`insert`, `update`, `delete`, `upsert`, …)
- Static shortcuts (`findById`, `findBy`, `findAllBy`, …)

```php
// Instance (fluent query builder):
$users = UserRepository::instance('u')
    ->where(Qb::eq('u.role', 'admin'))
    ->orderBy('u.name ASC')
    ->findAll();

// Static shortcuts:
$user = UserRepository::findById(42);

// Write:
$repo = new UserRepository();
$id   = $repo->insert($newUser);
$repo->update(['status' => 'inactive'], Qb::lt('last_login', '2024-01-01'));
```

---

## RepositoryView — Read-Only

```php
use Flytachi\Winter\K2\Ppa\Stereotype\RepositoryView;

class ReportRepository extends RepositoryView
{
    protected string $dbConfigClassName = DbConfig::class;
    public static string $table         = 'monthly_sales_view';
}
```

Has all read operations and query-builder methods. Has **no** write methods.
Ideal for database views or projections where writes are not allowed.

---

## RepositoryCrud — Write-Only

```php
use Flytachi\Winter\K2\Ppa\Stereotype\RepositoryCrud;

class EventLogRepository extends RepositoryCrud
{
    protected string $dbConfigClassName = DbConfig::class;
    public static string $table         = 'event_log';
}
```

Has all write operations and the query builder. Has **no** fetch methods.
Useful for append-only tables where reads happen elsewhere.

---

## CteRepo — Ad-Hoc Queries

`CteRepo` is a **final concrete** class. You instantiate it directly, passing the
config class name at construction time. Unlike the abstract stereotypes, it does
not require subclassing — use it for one-off queries that don't belong to a
dedicated repository.

```php
use Flytachi\Winter\K2\Ppa\Stereotype\CteRepo;

// Ad-hoc query against any table:
$repo = new CteRepo(DbConfig::class);
$rows = $repo
    ->from('audit_log al')
    ->joinLeft('users u', 'al.user_id = u.id')
    ->where(Qb::gt('al.created_at', '2024-01-01'))
    ->select('al.action, u.name, al.created_at')
    ->orderBy('al.created_at DESC')
    ->limit(100)
    ->findAll();
```

`Repo` has only read operations. For writes use a `RepositoryCrud` or `Repository`
subclass with `$table` set.

---

## instance() — Fluent Factory

All stereotypes inherit `instance()` from `RepositoryCore`. It is equivalent
to `new static()` but also accepts an optional alias:

```php
// Without alias:
$repo = UserRepository::instance();

// With alias — sets ->as('u') automatically:
$repo = UserRepository::instance('u');
// Same as:
$repo = (new UserRepository())->as('u');
```

`instance()` is particularly handy when chaining several query-builder calls
before calling a fetch method.

---

## mapIdentifierColumnName()

The default primary-key column is `'id'`. Override it to change the column used
by `findById()`, `findByIdOrThrow()`, etc.:

```php
class ProductRepository extends Repository
{
    protected string $dbConfigClassName = DbConfig::class;
    public static string $table         = 'products';

    public function mapIdentifierColumnName(): string
    {
        return 'product_id';
    }
}

$product = ProductRepository::findById('SKU-001');
// WHERE product_id = :iqb0
```
