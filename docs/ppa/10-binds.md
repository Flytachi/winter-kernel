# Binds — Manual Bind Injection

Normally, binds are collected automatically when you call `where()`, `join*()`,
`with()`, `union*()`, and `from()` with repository subqueries. The `binding()`
method is for cases where you need to inject binds manually — for example when
using a raw `select()` expression or composing custom SQL fragments.

---

## binding()

```php
final public function binding(?array $binds): static
```

Merges an array of `CDOBind` objects into the accumulated bind store
(`$sqlParts['binds']`). Passing `null` or an empty array is a safe no-op.

Binds are stored as an associative array keyed by placeholder name, so reusing
the same named `CDOBind` across multiple `Qb` conditions produces no duplicates —
each name appears exactly once regardless of how many times it is referenced.

```php
use Flytachi\Winter\Cdo\CDOBind;

$bind = new CDOBind(':user_id', $userId);

UserRepository::instance()
    ->select('*, (SELECT COUNT(*) FROM orders WHERE user_id = :user_id) AS order_count')
    ->binding([$bind])
    ->findAll();
```

---

## CDOBind — named parameter container

`CDOBind` holds a named placeholder and its value. The same bind can be reused
across multiple conditions:

```php
use Flytachi\Winter\Cdo\CDOBind;

$statusBind = new CDOBind(':status', 'active');

UserRepository::instance()
    ->select('*, CASE WHEN status = :status THEN 1 ELSE 0 END AS is_active')
    ->where(Qb::eq('status', $statusBind))   // registers :status automatically
    ->findAll();
// No need to call ->binding([$statusBind]) — where() already registers it.
// If :status is only used in a raw select() expression and nowhere in Qb,
// then ->binding([$statusBind]) is required.
```

---

## Accessing stored binds

`getSql('binds')` returns the current bind array without building the full SQL:

```php
$repo = UserRepository::instance()
    ->where(Qb::eq('status', 'active'))
    ->andWhere(Qb::gt('score', 80));

$binds = $repo->getSql('binds');
// Array of CDOBind objects accumulated so far
```

---

## How binds are used internally

Before each query is executed, `useBind()` iterates over `$sqlParts['binds']`
and calls `bindTypedValue()` (CDOStatement) or `bindValue()` (PDOStatement) on
the prepared statement:

```
prepare(SQL) → useBind(stmt) → execute() → fetch
```

Type detection is handled by `CDOStatement::bindTypedValue()` — it maps PHP
types to the appropriate `PDO::PARAM_*` constant automatically.

---

## Clearing binds

`cleanCache('binds')` removes only the binds part, leaving other SQL parts
intact. `cleanCache()` (no argument) resets everything.

```php
$repo->cleanCache('binds'); // keep WHERE/JOIN/etc, discard binds
$repo->cleanCache();        // full reset
```
