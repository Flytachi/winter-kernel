# SQL Management — buildSql, getSql, cleanCache

These methods inspect, build, and reset the accumulated query state.

---

## buildSql()

```php
public function buildSql(): string
```

Assembles all accumulated SQL parts into a complete query string and returns it.
Logs the built SQL at `DEBUG` level.

The clause order is always:
```
[WITH [RECURSIVE] …]
SELECT …
[FROM …]
[alias]
[JOIN …]
[WHERE …]
[GROUP BY …]
[HAVING …]
[UNION …]
[ORDER BY …]
[LIMIT … [OFFSET …]]
[FOR …]
```

```php
$sql = UserRepository::instance('u')
    ->where(Qb::eq('u.status', 'active'))
    ->orderBy('u.name ASC')
    ->limit(10)
    ->buildSql();

// SELECT u.id, u.name, u.email, u.status FROM users u
// WHERE u.status = :iqb0
// ORDER BY u.name ASC
// LIMIT 10
echo $sql;
```

Throws `RepositoryException` if any part of the build fails.

**Note:** `buildSql()` does **not** reset the accumulated state. Call
`cleanCache()` after if you need to reuse the instance.

---

## getSql()

```php
final public function getSql(?string $param = null): mixed
```

Two modes:

| Call | Returns |
|------|---------|
| `getSql()` | Full built SQL string (calls `buildSql()`) |
| `getSql('key')` | The value of `$sqlParts['key']`, or `null` if not set |

Available part keys:

| Key | Content |
|-----|---------|
| `'option'` | Custom SELECT expression set by `select()` |
| `'from'` | Custom FROM source set by `from()` |
| `'as'` | Table alias set by `as()` |
| `'join'` | Accumulated JOIN string |
| `'where'` | WHERE clause string |
| `'group'` | GROUP BY clause string |
| `'having'` | HAVING clause string |
| `'union'` | UNION/UNION ALL string |
| `'order'` | ORDER BY clause string |
| `'limit'` | LIMIT value (int) |
| `'offset'` | OFFSET value (int) |
| `'for'` | FOR locking expression |
| `'with'` | CTE definition string |
| `'with_recursive'` | `true` when `withRecursive()` was called |
| `'binds'` | Array of `CDOBind` objects |

```php
$repo = UserRepository::instance()
    ->where(Qb::eq('status', 'active'))
    ->orderBy('name ASC');

$repo->getSql('where');  // "WHERE status = :iqb0"
$repo->getSql('order');  // "ORDER BY name ASC"
$repo->getSql('binds');  // [CDOBind …]
$repo->getSql('limit');  // null (not set)
$repo->getSql();         // full SQL string
```

---

## cleanCache()

```php
final public function cleanCache(?string $param = null): void
```

Two modes:

| Call | Effect |
|------|--------|
| `cleanCache()` | Resets all accumulated SQL parts |
| `cleanCache('key')` | Removes only the named part; all others remain |

```php
$repo = UserRepository::instance()
    ->where(Qb::eq('status', 'active'))
    ->orderBy('name ASC')
    ->limit(10);

// Remove only ORDER BY:
$repo->cleanCache('order');
$repo->getSql('order'); // null
$repo->getSql('where'); // still "WHERE status = :iqb0"

// Full reset:
$repo->cleanCache();
$repo->buildSql(); // SELECT … FROM users  (clean state)
```

Calling `cleanCache` with a key that does not exist is a safe **no-op**.

---

## Query lifecycle

Fetch methods (`find`, `findAll`, `count`, etc.) call `cleanCache()` internally
after executing the query. This means a repository instance can be reused after
a fetch:

```php
$repo = UserRepository::instance()->where(Qb::eq('role', 'admin'));
$admins = $repo->findAll(); // executes + cleanCache()

// $repo is clean — ready for a new query:
$repo->where(Qb::eq('role', 'editor'));
$editors = $repo->findAll();
```
