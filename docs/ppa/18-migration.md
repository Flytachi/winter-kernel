# Migration — `db migrate` rules and the attribute catalog

This page describes everything the framework needs in your code in order
to migrate a database via the console: which classes get scanned, which
attributes participate, what SQL is generated, in what order it runs,
and what makes a re-run safe.

CLI reference for the `Db` command itself (`ping` / `sql` / `migrate`,
flags, options): [../configuration/06-db.md](../configuration/06-db.md).

---

## The big picture

```
   Scanner (Kernel::$pathRoot)
        ├── finds Repository implementations
        │
        ▼
   PPAMapping::scanningDeclaration()
        ├── for each repo:
        │     ├── instantiates the DbConfig class
        │     ├── reads #[Table] from the entity class (required, else skip)
        │     ├── builds Column structures from property attributes
        │     └── pushes a Structure\Table into Declaration
        │
        ▼
   Declaration                         ← grouped by DbConfig class
        ├── DeclarationItem (config A)
        │     ├── tables[]              ← collected via push()
        │     ├── extensions[]          ← auto-collected from #[Extension] on config
        │     └── migratable?           ← auto-collected from #[Migratable] on config
        │
        ▼
   `Db` command:
        ├── filters items by isMigratable()
        ├── sorts by priority (High → Normal → Low)
        ├── for each item:
        │     ├── emit + exec CREATE EXTENSION (pgsql only)
        │     ├── emit + exec CREATE SCHEMA   (pgsql only)
        │     ├── emit + exec CREATE TABLE
        │     ├── emit + exec CREATE INDEX (and other external statements)
        │     └── emit + exec ALTER TABLE … ADD CONSTRAINT
        │
        ▼
   Driver SQL  → real database
```

Each layer is independently testable; integration tests in
`tests/Integration/Cli/` cover the full chain end-to-end.

---

## Opt-in requirements

For a Repository to participate in `db migrate` the framework checks
**three** things in order. Failing any one removes the repo from the
migration scan silently.

1. **The repository class must be discoverable** — its file must live
   under `Kernel::$pathRoot` (or under a registered plugin's root).
   The scanner only walks paths it's been pointed at.
2. **The entity class must carry `#[Table]`** — the marker attribute
   from `Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table`. Without
   it `PPAMapping::scanDeclarationFilter()` short-circuits and the
   repo is ignored.
3. **The DbConfig class must carry `#[Migratable]`** — without it
   `DeclarationItem::isMigratable()` returns `false` and the Db command
   filters the item out before any SQL is emitted.

```php
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Migratable;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\K2\Ppa\Stereotype\Repository;

// (1) discoverable — lives under your app's pathRoot

// (3) opted into migration
#[Migratable]
final class AppDbConfig extends PgDbConfig { /* … */ }

// (2) entity is migratable
#[Table]
final class UserEntity { /* fields with column attributes */ }

final class UserRepository extends Repository
{
    protected string $dbConfigClassName = AppDbConfig::class;
    protected string $entityClassName   = UserEntity::class;
    public static string $table         = 'users';
}
```

---

## `#[Migratable]` — opting a DbConfig into migration

`Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Migratable`

| Parameter | Type | Default | Effect |
|---|---|---|---|
| `priority` | `MigratablePriority` | `Normal` | Migration ordering when more than one config is migratable. |

`MigratablePriority` is an `int`-backed enum:

```php
enum MigratablePriority: int
{
    case High   = 0;
    case Normal = 50;
    case Low    = 100;
}
```

The Db command sorts ascending — **lower value runs first**.

```php
#[Migratable]                                   // Normal — most cases
#[Migratable(MigratablePriority::High)]         // before others
#[Migratable(MigratablePriority::Low)]          // after others
```

Use cases for non-default priority:
- Reporting / replica configs that depend on the primary schema → `Low`
- Auth / session storage that other tables reference → `High`

Within the same priority, items run in the order returned by
`Declaration::getItems()` — i.e. the order the scanner discovered them
(typically alphabetical by repository class FQCN).

---

## `#[Extension]` — PostgreSQL extension declarations

`Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Extension`

PostgreSQL-only. Stack the attribute multiple times on the same config:

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `name` | `string` | required | `'uuid-ossp'`, `'pgcrypto'`, `'postgis'`, … |
| `version` | `?string` | `null` | Pins to `CREATE EXTENSION ... VERSION 'x'` |
| `schema` | `?string` | `null` | Installs into a named schema (must exist). |
| `cascade` | `bool` | `false` | Adds `CASCADE`. |

```php
#[Migratable]
#[Extension('uuid-ossp')]
#[Extension('pgcrypto')]
#[Extension('postgis', schema: 'gis', cascade: true)]
final class AppDbConfig extends PgDbConfig { /* … */ }
```

Generated SQL (run before any `CREATE SCHEMA`/`CREATE TABLE`):

```sql
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "postgis" WITH SCHEMA gis CASCADE;
```

`#[Extension]` on a non-pgsql config (`MySqlDbConfig` subclasses) is
silently ignored — the Db command gates extension emission on
`getDriver() === 'pgsql'`.

---

## Column attribute catalog

The framework's `ColumnMapping` walks every public property of the
entity, reads the attributes on it, and produces a `Structure\Column`.
Attributes fall into five categories.

### Primal — column SQL type

Required (or implied via a Hybrid). Set exactly **one** per property.

| Attribute | Generated SQL (pgsql / mysql) |
|---|---|
| `#[Integer]` | `INTEGER` / `INT` |
| `#[BigInteger]` | `BIGINT` |
| `#[SmallInteger]` | `SMALLINT` |
| `#[Boolean]` | `BOOLEAN` |
| `#[FloatType]` | `REAL` / `FLOAT` |
| `#[Double]` | `DOUBLE PRECISION` / `DOUBLE` |
| `#[Decimal(int $precision = 12, int $scale = 2)]` | `NUMERIC(p,s)` / `DECIMAL(p,s)` |
| `#[Varchar(int $length = 255)]` | `VARCHAR(N)` |
| `#[Char(int $length)]` | `CHAR(N)` |
| `#[Text]` | `TEXT` |
| `#[TextArray]` | `TEXT[]` / `JSON` (fallback) |
| `#[Json]` | `JSONB` / `JSON` |
| `#[Date]` | `DATE` |
| `#[Time]` | `TIME` |
| `#[DateTime]` | `TIMESTAMP WITHOUT TIME ZONE` / `DATETIME` |
| `#[Timestamp(bool $withTimeZone = true)]` | `TIMESTAMP WITH TIME ZONE` / `TIMESTAMP` |
| `#[Uuid(bool $asBinary = false)]` | `UUID` / `CHAR(36)` (or `BINARY(16)`) |
| `#[Binary(int $length = 255)]` | `BYTEA` / `VARBINARY(N)` |
| `#[Blob(string $size = 'default')]` | `BYTEA` / `BLOB` (TINY/MEDIUM/LONG) |
| `#[Type(string $definition)]` | escape hatch — emits `$definition` verbatim |

Most Primals enforce the PHP property type via `supports()` — e.g.
`#[Integer]` rejects a `string` property at scan time with
`InvalidArgumentException`.

### Hybrid — pre-canned attribute bundles

A Hybrid expands into a list of other attributes via
`getInstances()`. Convenient for common patterns.

| Attribute | Expands to |
|---|---|
| `#[Id]` | `#[Primary]` + `#[AutoIncrement]` + `#[NullableIs(false)]` + `#[Integer]` |
| `#[BigId]` | same with `#[BigInteger]` |
| `#[SmallId]` | same with `#[SmallInteger]` |
| `#[UuidPk]` | `#[Primary]` + `#[Uuid]` + `#[NullableIs(false)]` + `#[DefaultVal('gen_random_uuid()')]` (pgsql) / `'UUID()'` (mysql) |

```php
#[Table]
final class UserEntity
{
    #[Id]                              // auto-increment int PK
    public int $id;

    #[Varchar(100)]
    #[NullableIs(false)]
    #[Unique]                          // unique index on email
    public string $email;
}
```

### Sub — modifies a Primal

| Attribute | Effect |
|---|---|
| `#[AutoIncrement(bool $always = false)]` | Appends `AUTO_INCREMENT` (mysql) or `GENERATED BY DEFAULT/ALWAYS AS IDENTITY` (pgsql). |

### Idx — index declarations (`Mapping\Attributes\Idx`)

| Attribute | Generated structure |
|---|---|
| `#[Primary]` | `PRIMARY KEY` (merged with other columns' primaries into a composite key if multiple) |
| `#[Unique(columns: [], name: ?, method: BTREE, where: ?, opClass: ?)]` | `CREATE UNIQUE INDEX` |
| `#[Index(columns: [], name: ?, method: BTREE, where: ?, opClass: ?)]` | `CREATE INDEX` |

Repeatable (`IS_REPEATABLE`) — stack to create multiple indexes per
column. `columns: ['other']` adds extra columns to the index; the
property the attribute sits on is automatically prepended.

PostgreSQL-only options: `where` for partial indexes, `opClass` for
operator classes (e.g. `gin_trgm_ops`).

### Constraint — FK and CHECK

| Attribute | Notes |
|---|---|
| `#[ForeignKey(referencedTable, referencedColumn, onUpdate, onDelete, name?)]` | Local column name is auto-derived; framework passes it through to `Structure\ForeignKey::$columnName`. |
| `#[ForeignRepo(referencedRepoClass, onUpdate, onDelete, name?)]` | Resolves table+PK from the referenced repo's `originTable()`/`mapIdentifierColumnName()`. |
| `#[Check(string $expression, ?string $name)]` | Single `CHECK` predicate. |
| `#[CheckEnum(class-string<BackedEnum> $enumClassName, ?string $name)]` | Emits `col IN (...enum.cases())`. Backed enum only — throws on plain or non-backed. |

`FKAction` enum (`Mapping\Constants\FKAction`):
`RESTRICT` | `NO_ACTION` | `SET_DEFAULT` | `SET_NULL` | `CASCADE`.

### Additive — modifies the column's null/default

| Attribute | Effect |
|---|---|
| `#[NullableIs(bool $isNullable = true)]` | Overrides nullability derived from PHP type. |
| `#[DefaultVal(string $definition)]` | Raw SQL default (e.g. `'NOW()'`, `'gen_random_uuid()'`, `"'pending'"`). |

When `#[DefaultVal]` is absent, the framework derives a default from the
PHP property's default value:

| PHP default | Generated SQL |
|---|---|
| `null` (with nullable PHP type) | `DEFAULT NULL` |
| `true` / `false` | `DEFAULT TRUE` / `DEFAULT FALSE` |
| string | `DEFAULT '<value>'` |
| array | `DEFAULT '...'::jsonb` (pgsql) / `DEFAULT (...)` (mysql) |
| numeric | `DEFAULT <value>` |

---

## Migration execution rules

### Phase order inside one `DeclarationItem`

The `Db` command emits in this fixed order (matching the dependency
chain):

```
1. EXTENSIONS    (pgsql only)
2. SCHEMAS       (pgsql only — CREATE SCHEMA for the config's $schema)
3. TABLES        (CREATE TABLE — primary key declarations included)
4. INDEXES       (CREATE INDEX — emitted as external statements after the table)
5. CONSTRAINTS   (ALTER TABLE ... ADD CONSTRAINT — FK, CHECK)
```

Extensions first because table `DEFAULT` expressions may reference
extension-provided functions (`gen_random_uuid()` needs `pgcrypto`).
Schemas before tables because `CREATE TABLE schema.users` requires the
schema to exist. Indexes and constraints after the table because they
reference its columns.

### Cross-item order

Items are sorted by `MigratablePriority` ascending. Within the same
priority, scanner discovery order is preserved.

### Idempotency

Re-running `db migrate` is safe — every statement either uses an
`IF NOT EXISTS` clause or has its "object already exists" SQLSTATE
caught and badged `EXIST` (warning yellow) instead of `FAILED` (red):

| Object | Mechanism | pgsql SQLSTATE | mysql SQLSTATE |
|---|---|---|---|
| Extension | `IF NOT EXISTS` in DDL | — | n/a |
| Schema | per-statement catch | `42P06` | n/a |
| Table | per-statement catch | `42P07` | `42S01` |
| Index | per-statement catch | `42P07` | `42000` |
| Constraint | per-statement catch | `42710` | n/a |

Any other SQLSTATE produces `FAILED`. With `DEBUG=true` in `.env` the
underlying error message is printed.

### Flag-driven phase selection

```bash
call db migrate            # all phases (default: -e -s -t -i -c)
call db migrate -t -i      # tables + indexes only
call db migrate -e         # extensions only (pgsql)
```

See [../configuration/06-db.md](../configuration/06-db.md) for the full
flag matrix.

---

## What the framework does NOT do

Knowing the limits of the migration tooling avoids surprises in
production:

- **No diffing / drift detection.** `db migrate` is forward-only —
  it creates objects that don't exist. It does not detect columns that
  were added or removed in code since the last run. Use a dedicated
  migration tool (Phinx, Liquibase) or manual `ALTER TABLE` for schema
  evolution.
- **No DROP.** No `DROP TABLE`, no `DROP COLUMN`, no constraint removal.
- **No transactions.** Each statement is executed independently;
  PostgreSQL DDL statements can be transactional manually but the Db
  command doesn't wrap.
- **No data migration.** Only DDL. Seeding lives elsewhere.
- **No version tracking.** There is no `migrations` table. Every
  `db migrate` re-runs every statement (idempotently). For
  out-of-band schema changes that the framework's `db migrate`
  shouldn't touch, leave the entity un-decorated with `#[Table]` or
  the config un-decorated with `#[Migratable]`.

For real-world migration workflows the framework's `db migrate` is
typically used **only on first deploy / dev bootstrap**; production
schema changes go through dedicated migration tooling.

---

## See also

- [`02-configuration.md`](02-configuration.md) — repository properties
- [`15-declaration.md`](15-declaration.md) — `Declaration` / `DeclarationItem` internals
- [`../configuration/06-db.md`](../configuration/06-db.md) — Db CLI command reference
