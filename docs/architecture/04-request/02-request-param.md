# `#[RequestParam]`

Binds a query string parameter to a controller method parameter. A query parameter is a
key-value pair that follows the `?` separator in a URL — it is part of the request but
not part of the path that the router uses to select the route.

```php
use Flytachi\Winter\K2\Http\Request\Annotation\RequestParam;
```

---

## What Is a Query Parameter?

The query string is the portion of the URL that starts with `?` and contains one or more
`key=value` pairs separated by `&`:

```
https://api.example.com/orders?page=2&limit=10&status=active&ids[]=1&ids[]=2
                                └────────────────────────────────────────────┘
                                               query string
```

PHP parses this string into an associative array that maps keys to values. Array-valued
parameters use bracket notation — `ids[]=1&ids[]=2` is parsed into `['ids' => ['1', '2']]`.
All scalar values arrive as strings regardless of their content; the cast step in
`ParameterResolver` converts them to the PHP type declared on the parameter.

---

## How Values Reach the Controller

The query string is parsed by PHP before the request ever reaches the framework.
`HttpRequest::getQueryParams()` returns that parsed array directly (equivalent to `$_GET`
in FPM mode; populated from the raw URI in Swoole mode).

```
GET /orders?page=2&status=active&ids[]=1&ids[]=2
        │
        ▼
PHP parses query string
        │  queryParams = ['page' => '2', 'status' => 'active', 'ids' => ['1', '2']]
        ▼
Router::handle(HttpRequest, HttpResponse)
        │  matches route  orders  (no path variables)
        ▼
ParameterResolver::resolve(ReflectionMethod, request, response, pathParams=[])
        │
        │  for each method parameter with #[RequestParam]:
        │      1. determine lookup key  (annotation arg or PHP param name)
        │      2. fetch value from queryParams['key']  (with name normalization if no explicit key)
        │      3. check presence — 400 if required and absent
        │      4. cast string → PHP type (int, bool, Enum, array, …)
        │      5. place in argument list
        ▼
Controller::list(page: 2, status: Status::ACTIVE, ids: ['1', '2'])
```

The cast step (4) uses the same shared `ParameterResolver::cast()` logic that
`#[PathVariable]` and `#[RequestHeader]` also use. The controller always receives
a fully validated and typed value.

---

## Syntax

```php
#[RequestParam]              // key = PHP parameter name (with normalization fallback)
#[RequestParam('key_name')]  // key = explicit name (exact match, no normalization)
```

---

## Rules

1. The query key must match the annotation key, or the PHP parameter name when `name` is omitted.
2. When no explicit name is given, the lookup falls back through normalized variants (see [Key Name Normalization](#key-name-normalization)).
3. Required by default — an absent key throws `400 Bad Request`.
4. Nullable (`?T`) or a default value makes the parameter optional.
5. An empty string `""` is **not** treated as an absent value — it is a present value that fails validation for all types except `string`.
6. Union types (`int|string`) are forbidden — `LogicException` is thrown at startup.
7. `array` requires bracket notation in the query string: `?ids[]=1&ids[]=2`.

---

## Key Name Normalization

When no explicit name is given in the annotation, the resolver does not require the
client to use the exact PHP parameter name. It tries three key variants in order,
stopping at the first match:

```
PHP param name  →  lookup order
────────────────────────────────────────────────
$pName          →  pName  →  p_name  →  p-name
$pageSize       →  pageSize  →  page_size  →  page-size
$userId         →  userId  →  user_id  →  user-id
```

The fallback conversion is performed by `Tool::camelToSnake()` and `Tool::camelToKebab()`
from `Flytachi\Winter\Base\Tool`. If the exact key is found, the two fallback lookups
are never executed — there is no performance cost on the happy path.

**This normalization applies only when no explicit name is given.** An explicit
`#[RequestParam('p_name')]` performs a single exact-match lookup with no fallbacks.

```php
// Flexible — client may use any of: ?pName=  ?p_name=  ?p-name=
#[RequestParam] string $pName

// Strict — client must use exactly: ?p_name=
#[RequestParam('p_name')] string $pName
```

**Important:** normalization is one-directional — camelCase PHP name → snake/kebab
query key. It does not work in reverse. If the PHP parameter is already `$p_name`
(snake_case), the regex finds no uppercase letters and all three lookups resolve to
the same key `p_name`. In this case only `?p_name=` is matched — `?pName=` is not.

```
$p_name  →  p_name  →  p_name  →  p_name   (all identical — only exact match works)
```

Use camelCase PHP parameter names to get the full normalization benefit.

---

## Basic Usage

```php
#[GetMapping('items')]
public function index(
    #[RequestParam] int    $page,
    #[RequestParam] string $search,
): ResponseEntity
// GET /items?page=2&search=hello  →  $page = 2, $search = "hello"
```

---

## Custom Name

Use an explicit name when the PHP identifier would differ from the query key —
for example, when the API surface uses `snake_case` keys but PHP uses `camelCase`:

```php
#[RequestParam('page_size')] int $pageSize
// GET /items?page_size=25  →  $pageSize = 25
```

---

## Optional: Nullable / Default

A query parameter is required unless the parameter is explicitly marked optional:

```php
#[RequestParam] int     $page   = 1      // absent → 1
#[RequestParam] ?string $search = null   // absent → null
#[RequestParam] ?int    $limit           // absent → null
```

When the key is absent from the query string, the resolver checks in this order:
1. PHP default value present → return it
2. Type is nullable → return `null`
3. Otherwise → `400 Bad Request`

---

## Empty String Is Not Absent

Sending a key with no value (`?page=`) produces an empty string `""` in the query
params array. That is distinct from not sending the key at all:

```
?page=     → queryParams['page'] = ""   → value IS present → 400 (invalid int)
           (key absent)                 → value is absent   → default / null / 400
```

The only type for which an empty string is a valid, accepted value is `string` — any
other type treats it as a present-but-invalid value and returns `400`.

---

## Supported Types

All scalar values arrive as strings from the query string. Arrays arrive already parsed
by PHP's bracket-notation handling. The cast step validates and converts each value.

### `int`

`FILTER_VALIDATE_INT` is applied. Decimal points or non-digit characters cause failure.

```php
#[RequestParam] int $page
// ?page=3    → 3
// ?page=3.0  → 400: Query parameter 'page' must be an integer, got '3.0'
// ?page=abc  → 400: Query parameter 'page' must be an integer, got 'abc'
// absent     → 400: Required query parameter 'page' is missing
```

### `float`

`FILTER_VALIDATE_FLOAT` is applied. Integer strings are accepted.

```php
#[RequestParam] float $ratio
// ?ratio=1.5  → 1.5
// ?ratio=2    → 2.0
// ?ratio=abc  → 400: Query parameter 'ratio' must be a float, got 'abc'
```

### `bool`

`FILTER_VALIDATE_BOOLEAN` with `FILTER_NULL_ON_FAILURE` is applied. Any string outside
the accepted set is rejected explicitly — there is no silent fallback to `false`.

```php
#[RequestParam] bool $active
// ?active=true, ?active=1, ?active=yes, ?active=on   → true
// ?active=false, ?active=0, ?active=no, ?active=off  → false
// ?active=null  → 400: Query parameter 'active' must be a boolean (true/false/1/0/yes/no), got 'null'
```

### `string`

No conversion. The raw query value is returned as-is. An empty string is valid.

```php
#[RequestParam] string $search
// ?search=hello → "hello"
// ?search=      → ""       (valid — empty string is a legitimate string value)
// absent        → 400: Required query parameter 'search' is missing
```

### `array`

PHP's query string parser converts bracket notation into a native array before the
request reaches the framework. The resolver checks that the received value is already
an array — a plain scalar string is rejected.

```php
#[RequestParam] array $ids
// ?ids[]=1&ids[]=2  → ['1', '2']
// ?ids[]=foo        → ['foo']
// ?ids=1            → 400: Query parameter 'ids' must be an array (use bracket notation: key[]=val), got '1'

// Optional array with default
#[RequestParam] array $ids = []
// absent  → []
```

### `BackedEnum` (string-backed)

The string value is passed directly to `Enum::from()`. If the value is not a valid case,
the error message lists all allowed values.

```php
enum Status: string { case ACTIVE = 'active'; case INACTIVE = 'inactive'; }

#[RequestParam] Status $status
// ?status=active   → Status::ACTIVE
// ?status=unknown  → 400: Query parameter 'status' must be one of [active, inactive], got 'unknown'
```

### `BackedEnum` (int-backed)

The string is first validated with `FILTER_VALIDATE_INT` and converted to `int`, then
passed to `Enum::from()`. This prevents PHP from silently casting `"abc"` to `0`.

```php
enum Code: int { case OK = 1; case FAIL = 0; }

#[RequestParam] Code $code
// ?code=1    → Code::OK
// ?code=0    → Code::FAIL
// ?code=abc  → 400: Query parameter 'code' must be one of [1, 0], got 'abc'
// ?code=99   → 400: Query parameter 'code' must be one of [1, 0], got '99'
```

The backing type (`int` vs `string`) is determined at cast time via `ReflectionEnum`.

### `DateTimeImmutable` / `DateTime`

The string is passed to `new DateTimeImmutable()` or `new DateTime()`. An empty string
is explicitly rejected before the constructor is called, because PHP's date constructor
would otherwise silently return the current timestamp for an empty string.

```php
#[RequestParam] \DateTimeImmutable $from
// ?from=2024-01-31            → DateTimeImmutable(2024-01-31 00:00:00)
// ?from=2024-01-31T12:30:00  → DateTimeImmutable(2024-01-31 12:30:00)
// ?from=                     → 400: Query parameter 'from' has invalid date '' — expected ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)
// ?from=not-a-date            → 400: Query parameter 'from' has invalid date 'not-a-date' — expected ISO 8601 (...)

// Nullable — absent means null, empty string still fails:
#[RequestParam] ?\DateTimeImmutable $from = null
// absent     → null
// ?from=     → 400  (empty string is present-but-invalid, not absent)
```

### `BcMath\Number` *(requires ext-bcmath)*

Only available when the `bcmath` extension is loaded. `is_numeric()` is checked before
constructing, because the `BcMath\Number` constructor throws `ValueError` for non-numeric
strings — the check converts that into a proper `400` with a human-readable message.

The value is **always passed as a string** to preserve full decimal precision. A PHP
`float` intermediary would silently lose digits for values like `"1.0000000000000001"`.

```php
#[RequestParam] \BcMath\Number $amount
// ?amount=3.14159265358979  → BcMath\Number("3.14159265358979")  — exact
// ?amount=abc               → 400: Query parameter 'amount' must be a numeric value (int or decimal string), got 'abc'
```

### `Decimal\Decimal` *(requires ext-decimal)*

Identical precision guarantee to `BcMath\Number`. Requires the `decimal` extension.

```php
#[RequestParam] \Decimal\Decimal $price
// ?price=9.99  → Decimal\Decimal("9.99")  — exact
// ?price=abc   → 400: Query parameter 'price' must be a numeric value (int or decimal string), got 'abc'
```

---

## Full Example

A typical list endpoint with pagination, filtering, and search:

```php
#[GetMapping('orders')]
public function list(
    #[RequestParam] int                  $page    = 1,
    #[RequestParam] int                  $limit   = 20,
    #[RequestParam] ?string              $search  = null,
    #[RequestParam] ?OrderStatus         $status  = null,
    #[RequestParam] ?\DateTimeImmutable  $from    = null,
    #[RequestParam] ?\DateTimeImmutable  $to      = null,
    #[RequestParam] array                $ids     = [],
): ResponseEntity
```

```
GET /orders?page=2&limit=10&status=active&from=2024-01-01&ids[]=1&ids[]=2
```

Results in:

| Parameter | Raw value        | PHP value                    |
|-----------|------------------|------------------------------|
| `$page`   | `"2"`            | `2` (int)                    |
| `$limit`  | `"10"`           | `10` (int)                   |
| `$search` | absent           | `null`                       |
| `$status` | `"active"`       | `OrderStatus::ACTIVE`        |
| `$from`   | `"2024-01-01"`   | `DateTimeImmutable`          |
| `$to`     | absent           | `null`                       |
| `$ids`    | `['1', '2']`     | `['1', '2']` (array)         |

---

## Error Messages

| Situation             | HTTP | Message                                                                             |
|-----------------------|------|-------------------------------------------------------------------------------------|
| Missing required      | 400  | `Required query parameter 'page' is missing`                                        |
| Invalid int           | 400  | `Query parameter 'page' must be an integer, got 'abc'`                              |
| Invalid float         | 400  | `Query parameter 'ratio' must be a float, got 'abc'`                                |
| Invalid bool          | 400  | `Query parameter 'active' must be a boolean (true/false/1/0/yes/no), got 'null'`    |
| Scalar for array      | 400  | `Query parameter 'ids' must be an array (use bracket notation: key[]=val), got '1'` |
| Invalid enum          | 400  | `Query parameter 'status' must be one of [active, inactive], got 'x'`              |
| Invalid date          | 400  | `Query parameter 'from' has invalid date 'x' — expected ISO 8601 (...)`             |
| Invalid numeric       | 400  | `Query parameter 'amount' must be a numeric value (int or decimal string), got 'x'` |
| Union type on startup | —    | `LogicException: Union/intersection type on '$value' in ... is not supported`       |

---

## Related

| Topic | File |
|-------|------|
| Overview — resolution priority, type casting table | [00-overview.md](00-overview.md) |
| URL path segments | [01-path-variable.md](01-path-variable.md) |
| Full query string mapped to a DTO | [06-request-query.md](06-request-query.md) |
| HTTP headers | [04-request-header.md](04-request-header.md) |
