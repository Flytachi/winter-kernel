# Winter PPA — Documentation Overview

**PPA** (PHP Persistence API) is the repository layer for the Winter framework.
It wraps [Winter CDO](https://winterframe.net/docs/cdo) with a fluent SQL query builder,
entity hydration, and ready-made CRUD and read-only stereotypes.

**Full web documentation:** https://winterframe.net/docs/ppa

---

## How the pieces fit together

```
RepositoryCore (abstract)
    ├── implements RepositoryInterface   — full query-builder surface
    ├── implements RepositoryMappingInterface — originTable, mapIdentifierColumnName
    └── extends Stereotype (PSR-3 logger)

    ├── + RepositoryCrudTrait   → insert, insertBatch, update, delete, upsert, upsertBatch
    └── + RepositoryViewTrait   → find, findAll, findColumn, count, exists, rawFetch
                                   findById, findBy, findAllBy, *OrThrow

Stereotypes (concrete base classes to extend):
    ├── Repository      = RepositoryCore + CrudTrait + ViewTrait   (full access)
    ├── RepositoryView  = RepositoryCore + ViewTrait               (read-only)
    ├── RepositoryCrud  = RepositoryCore + CrudTrait               (write-only)
    └── CteRepo         = RepositoryCore + ViewTrait (final, ad-hoc, no fixed table)

Interfaces:
    ├── RepositoryInterface         — query-builder contract
    ├── RepositoryCrudInterface     — write operations contract
    └── RepositoryViewInterface     — read operations contract

Entities:
    ├── EntityInterface             — custom SELECT column mapping
    └── EntityException             — domain/lookup exception (HTTP 4xx)

Declaration helpers:
    ├── Declaration                 — registry of table structures per DB config
    └── DeclarationItem             — one DB config → N table structures,
                                       auto-collects #[Migratable] + #[Extension]

DbConfig attributes (consumed by `db migrate`):
    ├── #[Migratable(priority)]     — opts the config into the migration scan
    └── #[Extension(name, …)]       — PG extension to install (repeatable)

Connection pool:
    ├── PpaConnectionPool           — FPM singleton / Swoole coroutine pool
    ├── PpaPoolConfigInterface      — marks a config as pool-aware
    └── PpaPoolTrait                — default implementation (maxConnections=5, timeout=3s)
```

---

## Quick start

```php
// 1. Define a repository
class UserRepository extends Repository
{
    protected string $dbConfigClassName = DbConfig::class;
    protected string $entityClassName   = UserEntity::class;
    public static string $table         = 'users';
}

// 2. Read
$user  = UserRepository::findById(42);
$users = UserRepository::instance('u')
    ->joinLeft('orders o', 'u.id = o.user_id')
    ->where(Qb::eq('u.status', 'active'))
    ->orderBy('u.id DESC')
    ->limit(20)
    ->findAll();

// 3. Write
$repo = new UserRepository();
$id   = $repo->insert(['name' => 'Alice', 'email' => 'alice@example.com']);
$repo->update(['status' => 'inactive'], Qb::lt('last_login', '2024-01-01'));
```

---

## Documentation Index

### Setup & Architecture

| # | File | Contents |
|---|------|----------|
| 01 | [01-stereotypes.md](01-stereotypes.md) | Choosing the right stereotype: `Repository`, `RepositoryView`, `RepositoryCrud`, `Repo` |
| 02 | [02-configuration.md](02-configuration.md) | `$dbConfigClassName`, `$entityClassName`, `$table`, `$schema`, `EntityInterface` |

### Query Builder — SELECT

| # | File | Contents |
|---|------|----------|
| 03 | [03-select-from.md](03-select-from.md) | `select()`, `from()`, `as()` — controlling what and where |
| 04 | [04-joins.md](04-joins.md) | `join`, `joinLeft`, `joinRight`, `joinInner`, `joinCross` — string and subquery forms |
| 05 | [05-where.md](05-where.md) | `where()`, `andWhere()`, `orWhere()`, `xorWhere()` |
| 06 | [06-group-having.md](06-group-having.md) | `groupBy()`, `having()` |
| 07 | [07-union.md](07-union.md) | `union()`, `unionAll()` |
| 08 | [08-order-limit.md](08-order-limit.md) | `orderBy()`, `limit()`, `forBy()` |
| 09 | [09-with-cte.md](09-with-cte.md) | `with()`, `withRecursive()`, `MATERIALIZED` modifier |
| 10 | [10-binds.md](10-binds.md) | `binding()` — manual bind injection |
| 11 | [11-sql-management.md](11-sql-management.md) | `buildSql()`, `getSql()`, `cleanCache()` |

### Execution

| # | File | Contents |
|---|------|----------|
| 12 | [12-view-fetch.md](12-view-fetch.md) | `find`, `findAll`, `findColumn`, `count`, `exists`, `rawFetch` |
| 13 | [13-static-finders.md](13-static-finders.md) | `findById`, `findBy`, `findAllBy`, `findByIdOrThrow`, `findByOrThrow` |
| 14 | [14-crud.md](14-crud.md) | `insert`, `insertBatch`, `update`, `delete`, `upsert`, `upsertBatch` |

### Utilities

| # | File | Contents |
|---|------|----------|
| 15 | [15-declaration.md](15-declaration.md) | `Declaration`, `DeclarationItem` — schema structure registry, auto-collection of `#[Migratable]` / `#[Extension]` |
| 16 | [16-advanced-examples.md](16-advanced-examples.md) | Real-world combinations: pagination, reporting, CTEs, multi-join |
| 17 | [17-pool.md](17-pool.md) | `PpaConnectionPool`, `PpaPoolConfigInterface`, `PpaPoolTrait` — FPM & Swoole connection pool |
| 18 | [18-migration.md](18-migration.md) | `db migrate` rules — `#[Migratable]`, `#[Extension]`, full column attribute catalog, execution order, idempotency |
