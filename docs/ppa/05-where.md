# WHERE Conditions

Conditions are expressed using the CDO `Qb` (Query Builder) — see the
[CDO documentation](https://winterframe.net/docs/cdo) for all available
operators. PPA wraps Qb conditions into `WHERE`, `AND`, `OR`, and `XOR` clauses.

---

## where() — Set the WHERE Clause

```php
final public function where(?Qb $qb): static
```

Replaces any previously set WHERE clause. Passing `null` is a safe no-op.
Binds from the `Qb` object are merged into `$sqlParts['binds']` automatically.

```php
UserRepository::instance()
    ->where(Qb::eq('status', 'active'))
    ->buildSql();
// SELECT … FROM users WHERE status = :iqb0

// Compound condition with Qb::and():
UserRepository::instance()
    ->where(Qb::and(
        Qb::eq('status', 'active'),
        Qb::gt('score', 80),
    ))
    ->buildSql();
// SELECT … FROM users WHERE (status = :iqb0 AND score > :iqb1)

// null is a no-op:
$filter = null;
UserRepository::instance()->where($filter)->findAll(); // no WHERE clause
```

---

## andWhere() — Append AND Condition

```php
final public function andWhere(Qb $qb): static
```

Appends `AND condition` to the existing WHERE clause. If no WHERE clause has
been set yet, behaves exactly like `where()`.

```php
UserRepository::instance()
    ->where(Qb::eq('role', 'editor'))
    ->andWhere(Qb::eq('status', 'active'))
    ->andWhere(Qb::gt('score', 50))
    ->buildSql();
// WHERE role = :iqb0 AND status = :iqb1 AND score > :iqb2

// Works without a prior where():
UserRepository::instance()
    ->andWhere(Qb::eq('status', 'active'))
    ->buildSql();
// WHERE status = :iqb0
```

---

## orWhere() — Append OR Condition

```php
final public function orWhere(Qb $qb): static
```

Appends `OR condition` to the existing WHERE clause.

```php
UserRepository::instance()
    ->where(Qb::eq('status', 'active'))
    ->orWhere(Qb::eq('status', 'trial'))
    ->buildSql();
// WHERE status = :iqb0 OR status = :iqb1
```

---

## xorWhere() — Append XOR Condition

```php
final public function xorWhere(Qb $qb): static
```

Appends `XOR condition`. Supported natively in MySQL; in PostgreSQL use
`Qb::custom()` with an equivalent expression.

```php
UserRepository::instance()
    ->where(Qb::eq('is_admin', true))
    ->xorWhere(Qb::eq('is_moderator', true))
    ->buildSql();
// WHERE is_admin IS TRUE XOR is_moderator IS TRUE
```

---

## Operator precedence

`andWhere`, `orWhere`, and `xorWhere` append at the **top level** without
additional parentheses. To control grouping use `Qb::and()`, `Qb::or()`, or
`Qb::clip()` inside a single `where()` call:

```php
// This means: (status = 'active') OR (role = 'admin' AND score > 90)
UserRepository::instance()
    ->where(Qb::or(
        Qb::eq('status', 'active'),
        Qb::and(
            Qb::eq('role', 'admin'),
            Qb::gt('score', 90),
        ),
    ))
    ->buildSql();
// WHERE (status = :iqb0 OR (role = :iqb1 AND score > :iqb2))
```

---

## Conditional WHERE (dynamic filters)

A common pattern is to build conditions dynamically:

```php
function searchUsers(
    ?string $status = null,
    ?string $role   = null,
    ?int    $minAge = null
): array {
    $repo = UserRepository::instance();

    if ($status !== null) {
        $repo->andWhere(Qb::eq('status', $status));
    }
    if ($role !== null) {
        $repo->andWhere(Qb::eq('role', $role));
    }
    if ($minAge !== null) {
        $repo->andWhere(Qb::gte('age', $minAge));
    }

    return $repo->findAll();
}
```

Alternatively use `Qb::empty()` as a neutral starting point and `addAnd()`:

```php
$qb = Qb::empty();
if ($status !== null) $qb->addAnd(Qb::eq('status', $status));
if ($role   !== null) $qb->addAnd(Qb::eq('role', $role));

UserRepository::instance()->where($qb)->findAll();
```
