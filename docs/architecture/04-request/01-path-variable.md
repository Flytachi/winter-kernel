# `#[PathVariable]`

Binds a URL path segment to a controller method parameter. A path variable is a named
placeholder embedded inside the route URL pattern — it captures a dynamic portion of
the request path and delivers it, cast to the declared PHP type, as a method argument.

```php
use Flytachi\Winter\Kernel\Http\Request\Annotation\PathVariable;
```

---

## What Is a Path Variable?

A REST URL like `/users/42` carries the resource identifier `42` as part of the path
itself, not as a query string parameter. The route pattern declares where that segment
lives and what shape it has:

```
Pattern:  users/{id:\d+}
URL:      users/42
          └─────┘
          captured as  id = "42"
```

The curly-brace syntax `{name}` or `{name:regex}` is a route placeholder. The optional
regex constraint (`:` followed by a PCRE expression) restricts which URLs can match the
route — `/users/abc` would not match `{id:\d+}` because `\d+` requires one or more digits.

Multiple variables can appear anywhere in the pattern and in any order:

```
Pattern:  users/{userId:\d+}/posts/{postId:\d+}/comments/{commentId:\d+}
URL:      users/3/posts/17/comments/4
Captured: userId = "3",  postId = "17",  commentId = "4"
```

---

## How Values Reach the Controller

The Router extracts all captured segments from the URL and stores them as a plain
`string => string` map. Every value at this stage is a raw string, regardless of
what PHP type the controller parameter declares.

```
GET /users/42/posts/7
        │
        ▼
Router::handle(HttpRequest, HttpResponse)
        │  matches pattern  users/{userId:\d+}/posts/{postId:\d+}
        │  regex captures:  ['userId' => '42', 'postId' => '7']
        ▼
ParameterResolver::resolve(ReflectionMethod, request, response, pathParams)
        │
        │  for each method parameter:
        │      1. detect #[PathVariable] (or name-match fallback)
        │      2. determine lookup key  (annotation arg or PHP param name)
        │      3. fetch raw string from pathParams['key']
        │      4. check presence — 400 if required and absent
        │      5. cast string → PHP type (int, bool, Enum, …)
        │      6. place in argument list
        ▼
Controller::method(userId: 42, postId: 7)
```

The cast step (5) is the same shared `ParameterResolver::cast()` logic that
`#[RequestParam]` and `#[RequestHeader]` also use. All validation and error
production happens there — the controller never receives an invalid value.

---

## Syntax

```php
#[PathVariable]               // key = PHP parameter name
#[PathVariable('segmentName')] // key = explicit name
```

---

## Rules

1. The placeholder name in the route pattern must match the annotation key (or parameter name when omitted).
2. If `name` is omitted, the PHP parameter name is used as the lookup key.
3. Required by default — a missing segment throws `400 Bad Request`.
4. Nullable (`?T`) or a default value makes the parameter optional.
5. Union types (`int|string`) are forbidden — `LogicException` is thrown at startup.
6. Every scalar value is validated before cast — an invalid value throws `400 Bad Request`.

---

## Basic Usage

```php
#[GetMapping('users/{id:\d+}')]
public function show(#[PathVariable] int $id): ResponseEntity
// GET /users/42  →  $id = 42
```

The parameter name `$id` matches the placeholder `{id:\d+}`, so no explicit name is needed.

---

## Custom Name

When the PHP parameter name differs from the placeholder in the pattern:

```php
#[GetMapping('users/{userId:\d+}')]
public function show(#[PathVariable('userId')] int $id): ResponseEntity
// GET /users/7  →  $id = 7
```

The annotation argument `'userId'` is the lookup key; `$id` is just the local variable name.

---

## Auto-Match (No Annotation)

When no annotation is present and the parameter name matches a captured segment exactly,
the resolver fills it automatically (priority 12 in the overall resolution order):

```php
#[GetMapping('users/{id:\d+}')]
public function show(int $id): ResponseEntity
// GET /users/5  →  $id = 5
```

This works because `$id` equals the placeholder name `id`. If the names do not match and
there is no `#[PathVariable]` annotation, the parameter falls through to later resolution
rules — if nothing else matches and there is no default or nullable marker, a
`RuntimeException` is thrown.

---

## Multiple Variables

```php
#[GetMapping('users/{userId:\d+}/posts/{postId:\d+}')]
public function post(
    #[PathVariable] int $userId,
    #[PathVariable] int $postId,
): ResponseEntity
// GET /users/3/posts/12  →  $userId = 3, $postId = 12
```

Each parameter is resolved independently; declaration order does not have to match the
order of segments in the URL.

---

## Optional: Nullable / Default

A path variable is required unless the parameter is explicitly marked optional:

```php
// Nullable — returns null when the segment is absent
#[PathVariable] ?int $id

// Default — uses the literal default when the segment is absent
#[PathVariable] int $id = 0

// Both — nullable + default (null is the effective value when absent)
#[PathVariable] ?int $id = null
```

When the source value is absent, the resolver checks in this order:
1. PHP default value present → return it
2. Type is nullable → return `null`
3. Otherwise → `400 Bad Request`

---

## Supported Types

All values arrive from the URL as plain strings. The cast step converts each string
to the declared PHP type and throws `400` if the input cannot be validly converted.

### `int`

`FILTER_VALIDATE_INT` is applied. Decimal points or non-digit characters cause failure.

```php
#[PathVariable] int $id
// "42"   → 42
// "3.14" → 400: Path variable 'id' must be an integer, got '3.14'
// "abc"  → 400: Path variable 'id' must be an integer, got 'abc'
```

### `float`

`FILTER_VALIDATE_FLOAT` is applied. Integer strings are accepted.

```php
#[PathVariable] float $ratio
// "3.14" → 3.14
// "2"    → 2.0
// "abc"  → 400: Path variable 'ratio' must be a float, got 'abc'
```

### `bool`

`FILTER_VALIDATE_BOOLEAN` with `FILTER_NULL_ON_FAILURE` is applied. Any string outside
the accepted set is rejected explicitly — there is no silent fallback to `false`.

```php
#[PathVariable] bool $active
// "true", "1", "yes", "on"   → true
// "false", "0", "no", "off"  → false
// "maybe"  → 400: Path variable 'active' must be a boolean (true/false/1/0/yes/no), got 'maybe'
```

### `string`

No conversion. The raw segment value is returned as-is.

```php
#[PathVariable] string $slug
// "hello-world" → "hello-world"
// ""            → ""   (empty string is valid for string)
```

### `BackedEnum` (string-backed)

The string value is passed directly to `Enum::from()`. If the value is not a valid case,
the error message lists all allowed values.

```php
enum Status: string { case ACTIVE = 'active'; case INACTIVE = 'inactive'; }

#[PathVariable] Status $status
// "active"  → Status::ACTIVE
// "unknown" → 400: Path variable 'status' must be one of [active, inactive], got 'unknown'
```

### `BackedEnum` (int-backed)

The string is first validated with `FILTER_VALIDATE_INT` and converted to `int`, then
passed to `Enum::from()`. This prevents PHP from silently casting `"abc"` to `0`.

```php
enum Code: int { case OK = 1; case FAIL = 0; }

#[PathVariable] Code $code
// "1"   → Code::OK
// "0"   → Code::FAIL
// "abc" → 400: Path variable 'code' must be one of [1, 0], got 'abc'
// "99"  → 400: Path variable 'code' must be one of [1, 0], got '99'
```

The backing type (`int` vs `string`) is determined at cast time via `ReflectionEnum`.

### `DateTimeImmutable` / `DateTime`

The string is passed to `new DateTimeImmutable()` or `new DateTime()`. An empty string
is explicitly rejected before the constructor is called, because PHP's date constructor
would otherwise silently return the current timestamp for an empty string.

```php
#[PathVariable] \DateTimeImmutable $date
// "2024-01-31"          → DateTimeImmutable(2024-01-31 00:00:00)
// "2024-01-31T12:00:00" → DateTimeImmutable(2024-01-31 12:00:00)
// ""                    → 400: Path variable 'date' has invalid date '' — expected ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)
// "not-a-date"          → 400: Path variable 'date' has invalid date 'not-a-date' — expected ISO 8601 (...)
```

### `BcMath\Number` *(requires ext-bcmath)*

Only available when the `bcmath` extension is loaded. `is_numeric()` is checked before
constructing, because the `BcMath\Number` constructor throws `ValueError` for non-numeric
strings — the check converts that into a proper `400` with a human-readable message.

The value is **always passed as a string** to preserve full decimal precision. A PHP
`float` cast would silently lose digits for values like `"1.0000000000000001"`.

```php
#[PathVariable] \BcMath\Number $amount
// "3.14159265358979" → BcMath\Number("3.14159265358979")  — exact
// "abc"              → 400: Path variable 'amount' must be a numeric value (int or decimal string), got 'abc'
```

### `Decimal\Decimal` *(requires ext-decimal)*

Identical precision guarantee to `BcMath\Number`. Requires the `decimal` extension.

```php
#[PathVariable] \Decimal\Decimal $amount
// "3.14159265358979" → Decimal\Decimal("3.14159265358979")  — exact
// "abc"              → 400: Path variable 'amount' must be a numeric value (int or decimal string), got 'abc'
```

---

## Error Messages

| Situation             | HTTP | Message                                                                         |
|-----------------------|------|---------------------------------------------------------------------------------|
| Missing required      | 400  | `Path variable 'id' is missing`                                                 |
| Invalid int           | 400  | `Path variable 'id' must be an integer, got 'abc'`                              |
| Invalid float         | 400  | `Path variable 'ratio' must be a float, got 'abc'`                              |
| Invalid bool          | 400  | `Path variable 'active' must be a boolean (true/false/1/0/yes/no), got 'maybe'` |
| Invalid enum          | 400  | `Path variable 'status' must be one of [active, inactive], got 'unknown'`       |
| Invalid date          | 400  | `Path variable 'date' has invalid date 'x' — expected ISO 8601 (...)`           |
| Invalid numeric       | 400  | `Path variable 'amount' must be a numeric value (int or decimal string), got 'x'` |
| Union type on startup | —    | `LogicException: Union/intersection type on '$id' in ... is not supported`      |

---

## Related

| Topic | File |
|-------|------|
| Overview — resolution priority, type casting table | [00-overview.md](00-overview.md) |
| Query string parameters | [02-request-param.md](02-request-param.md) |
| HTTP headers | [04-request-header.md](04-request-header.md) |
