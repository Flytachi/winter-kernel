# Static Finders

Static convenience methods that create a fresh repository instance, apply a
condition, and execute a fetch — all in one call. Available on `Repository`,
`RepositoryView`, and `Repo` (via `RepositoryViewTrait`).

---

## findById()

```php
final public static function findById(
    int|string  $id,
    ?string     $entityClassName = null
): ?object
```

Fetches a single record by primary key. Uses `mapIdentifierColumnName()` to
determine the PK column (default: `'id'`).

```php
$user = UserRepository::findById(42);          // object or null
$user = UserRepository::findById('usr_001');   // string PK
$raw  = UserRepository::findById(42, \stdClass::class); // override entity
```

Equivalent to:
```php
UserRepository::instance()
    ->where(Qb::eq('id', 42))
    ->find();
```

---

## findBy()

```php
final public static function findBy(
    Qb      $qb,
    ?string $entityClassName = null
): ?object
```

Fetches the first record matching a `Qb` condition.

```php
$user = UserRepository::findBy(Qb::eq('email', 'alice@example.com'));
// object(UserEntity) or null

$user = UserRepository::findBy(
    Qb::and(
        Qb::eq('status', 'active'),
        Qb::eq('role', 'admin'),
    )
);
```

---

## findAllBy()

```php
final public static function findAllBy(
    ?Qb     $qb = null,
    ?string $entityClassName = null
): array
```

Fetches all records matching a condition. Passing `null` returns all rows
(equivalent to `SELECT * FROM table`).

```php
// All active users:
$users = UserRepository::findAllBy(Qb::eq('status', 'active'));

// All records (no filter):
$all = UserRepository::findAllBy();

// With compound condition:
$users = UserRepository::findAllBy(
    Qb::and(
        Qb::in('role', ['admin', 'editor']),
        Qb::eq('status', 'active'),
    )
);
```

---

## findByIdOrThrow()

```php
final public static function findByIdOrThrow(
    int|string  $id,
    ?string     $entityClassName = null,
    string      $message         = 'Entity not found',
    HttpCode    $httpCode        = HttpCode::NOT_FOUND
): object
```

Same as `findById()` but throws `EntityException` if the record is not found.
The exception carries an HTTP status code (default `404 NOT_FOUND`) for use
in HTTP response handling.

```php
// Throws EntityException(404) if not found:
$user = UserRepository::findByIdOrThrow(42);

// Custom message and HTTP code:
$user = UserRepository::findByIdOrThrow(
    id:           $id,
    message:      'User does not exist',
    httpCode:     HttpCode::GONE,
);
```

---

## findByOrThrow()

```php
final public static function findByOrThrow(
    Qb       $qb,
    ?string  $entityClassName = null,
    string   $message         = 'Entity not found',
    HttpCode $httpCode        = HttpCode::NOT_FOUND
): object
```

Same as `findBy()` but throws `EntityException` if no record matches.

```php
$user = UserRepository::findByOrThrow(
    Qb::eq('token', $resetToken),
    message: 'Invalid or expired reset token',
    httpCode: HttpCode::UNPROCESSABLE_ENTITY,
);
```

---

## EntityException

`findByIdOrThrow()` and `findByOrThrow()` throw
`Flytachi\Winter\K2\Ppa\Entity\EntityException` on miss.
It extends the Winter framework exception and is logged at `WARNING` level.

```php
use Flytachi\Winter\K2\Ppa\Entity\EntityException;

try {
    $user = UserRepository::findByIdOrThrow($id);
} catch (EntityException $e) {
    // $e->getCode() === 404 (or the HttpCode you passed)
    // $e->getMessage() === 'Entity not found' (or your message)
}
```

---

## Comparison

| Method | Condition | Result | Throws on miss |
|--------|-----------|--------|---------------|
| `findById($id)` | PK = $id | `?object` | No |
| `findBy($qb)` | custom Qb | `?object` | No |
| `findAllBy($qb)` | custom Qb / null | `array` | No |
| `findByIdOrThrow($id)` | PK = $id | `object` | Yes — `EntityException` |
| `findByOrThrow($qb)` | custom Qb | `object` | Yes — `EntityException` |
