# Configuration — Setting Up a Repository

Every repository class needs at minimum a `$dbConfigClassName` and a `$table`.
All other properties are optional.

---

## Required properties

### `$dbConfigClassName`

```php
protected string $dbConfigClassName = DbConfig::class;
```

The fully-qualified class name of a `DbConfigInterface` implementation.
The connection is resolved lazily through `ConnectionPool` — no connection is
opened until the first query is actually executed.

If this property is not set in a subclass, the constructor throws
`RepositoryException` immediately.

### `$table`

```php
public static string $table = 'users';
```

The bare table name (without schema). Combined with `$schema` at runtime by
`originTable()`:

```php
// $schema = 'public', $table = 'users'
$repo->originTable(); // → 'public.users'

// $schema = null, $table = 'users'
$repo->originTable(); // → 'users'
```

Declared `public static` so it can be referenced externally without instantiation:

```php
echo UserRepository::$table; // 'users'
```

---

## Optional properties

### `$entityClassName`

```php
protected string $entityClassName = \stdClass::class; // default
```

The class to hydrate query results into. Defaults to `\stdClass`.

When set to a class that extends `\stdClass`, rows are still fetched as
`\stdClass` (wildcard SELECT is used).

When set to a plain PHP class (not extending `\stdClass`), PPA reads the class
properties via `get_class_vars()` and builds an explicit `SELECT` column list:

```php
class UserEntity
{
    public int    $id;
    public string $name;
    public string $email;
    public string $status;
}

class UserRepository extends Repository
{
    protected string $dbConfigClassName = DbConfig::class;
    protected string $entityClassName   = UserEntity::class;
    public static string $table         = 'users';
}

// SQL generated: SELECT id, name, email, status FROM users
```

### `$schema`

```php
protected ?string $schema = null; // default
```

Overrides the schema name from the CDO config. If left `null`, the schema is
taken from `DbConfigInterface::getSchema()`. Set explicitly when a repository
targets a schema different from the connection default:

```php
class ArchiveUserRepository extends Repository
{
    protected string $dbConfigClassName = DbConfig::class;
    protected string $entityClassName   = UserEntity::class;
    protected ?string $schema           = 'archive';
    public static string $table         = 'users';
}

// originTable() → 'archive.users'
```

---

## EntityInterface — Custom Column Mapping

Implement `EntityInterface` to control exactly which SQL expression maps to each
property. Return a `[propertyName => 'sql_expression']` array from `selection()`.
Properties absent from the map are selected by plain name (with alias prefix if set).

```php
use Flytachi\Winter\K2\Ppa\Entity\EntityInterface;

class UserEntity implements EntityInterface
{
    public int    $id;
    public string $fullName;
    public string $email;
    public string $createdAt;

    public static function selection(): array
    {
        return [
            'fullName'  => "CONCAT(u.first_name, ' ', u.last_name) AS fullName",
            'createdAt' => 'DATE_FORMAT(u.created_at, \'%Y-%m-%d\') AS createdAt',
        ];
    }
}
```

With `->as('u')`:
```sql
SELECT u.id,
       CONCAT(u.first_name, ' ', u.last_name) AS fullName,
       u.email,
       DATE_FORMAT(u.created_at, '%Y-%m-%d') AS createdAt
FROM users u
```

---

## Multiple repositories on the same table

You can have multiple repository classes for the same table — for example, one
read-only projection and one full-access repository:

```php
class UserRepository extends Repository
{
    protected string $dbConfigClassName = DbConfig::class;
    protected string $entityClassName   = UserEntity::class;
    public static string $table         = 'users';
}

class UserSummaryRepository extends RepositoryView
{
    protected string $dbConfigClassName = DbConfig::class;
    protected string $entityClassName   = UserSummaryEntity::class;
    public static string $table         = 'users';
}
```

---

## Getters

| Method | Returns |
|--------|---------|
| `getDbConfigClassName()` | The config class name string |
| `getEntityClassName()` | The entity class name string |
| `getSchema()` | Active schema or `null` |
| `originTable()` | `'schema.table'` or `'table'` or `''` |
| `db()` | Active `CDO` connection |

---

## DbConfig class attributes

The repository's `$dbConfigClassName` points at a `DbConfigInterface`
implementation. Two attributes can decorate that class — both are
consumed by the migration tooling (`db migrate`), not by the repository
itself.

### `#[Migratable]` — opt-in for `db migrate`

`Mapping\Attributes\Config\Migratable`. Without it the config (and
every repository pointing at it) is **silently skipped** by `db sql`
and `db migrate` — even if entities have `#[Table]`.

```php
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Migratable;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\MigratablePriority;

#[Migratable]                                   // priority: Normal
final class AppDbConfig extends PgDbConfig { /* … */ }

#[Migratable(MigratablePriority::High)]         // runs before Normal configs
final class AuthDbConfig extends PgDbConfig { /* … */ }
```

### `#[Extension]` — PG extension to install (repeatable)

`Mapping\Attributes\Config\Extension`. PostgreSQL-only — silently
ignored on mysql/mariadb at the SQL-generation step.

```php
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Extension;

#[Migratable]
#[Extension('uuid-ossp')]
#[Extension('pgcrypto', cascade: true)]
#[Extension('postgis', schema: 'gis', version: '3.4')]
final class AppDbConfig extends PgDbConfig { /* … */ }
```

The Db command emits these as `CREATE EXTENSION IF NOT EXISTS …` before
any `CREATE SCHEMA`/`CREATE TABLE` so table `DEFAULT` expressions can
reference extension functions (e.g. `gen_random_uuid()` from `pgcrypto`).

Full rules and the complete column-side attribute catalog live in
[18-migration.md](18-migration.md).
