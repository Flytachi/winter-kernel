# Request Parameter Binding — Overview

Winter K2 resolves controller method parameters automatically at request time.
You declare what you need via PHP type hints and attributes — the framework reads
the request, validates the value, casts it to the declared type, and injects it
into your method. No manual `$_GET`, `$_POST`, or `json_decode` in controller code.

This system is implemented in `ParameterResolver` and is modelled after Spring Boot's
`@RequestParam`, `@PathVariable`, `@RequestBody` family of annotations.

---

## How It Works

When a route is matched, the router calls `ParameterResolver::resolve()` before
invoking the controller method. The resolver iterates over every parameter of the
method in declaration order and decides how to fill it:

```
HTTP Request
    │
    ▼
Router::handle()
    │  matches route, extracts path params
    ▼
ParameterResolver::resolve(ReflectionMethod, HttpRequest, HttpResponse, pathParams[])
    │
    ├─ for each parameter:
    │       1. read type + attributes
    │       2. locate source (path / query / body / header / file)
    │       3. validate presence (required?)
    │       4. cast to declared PHP type
    │       5. inject into argument list
    ▼
Controller::method(...$args)
```

Each parameter is resolved independently. The resolver checks annotations in a fixed
priority order — the first match wins. If nothing matches and the parameter has no
default and is not nullable, a `RuntimeException` is thrown (misconfigured controller,
caught at startup in Swoole mode).

---

## Priority Order

| Priority | Annotation / Type         | Source                            | Notes                              |
|----------|---------------------------|-----------------------------------|------------------------------------|
| 1        | `#[PathVariable]`         | URL path segment                  | Required unless nullable / default |
| 2        | `#[RequestParam]`         | Query string `?key=val`           | Required unless nullable / default |
| 3        | `#[RequestBody]`          | Raw request body                  | Type determines parse strategy     |
| 4        | `#[RequestFile]`          | Multipart file upload             | Required unless nullable / default |
| 5        | `#[RequestJson]`          | Body — forced JSON parse          | `array` / `stdClass` / any class   |
| 6        | `#[RequestForm]`          | Body — forced form parse          | `array` / `stdClass` / any class   |
| 7        | `#[RequestXml]`           | Body — forced XML parse           | `array` / `stdClass` / any class   |
| 8        | `#[RequestQuery]`         | Full query string as DTO          | Always optional                    |
| 9        | `#[RequestHeader]`        | HTTP request header               | Required unless nullable / default |
| 10       | `HttpRequest` subclass    | Raw request object injected       | Framework type, no annotation      |
| 11       | `HttpResponse` subclass   | Raw response object injected      | Framework type, no annotation      |
| 12       | Name match in path params | Path segment without annotation   | Required unless nullable / default |
| 13       | PHP default value         | Parameter default literal         | Used when source is absent         |
| 14       | Nullable type (`?T`)      | Returns `null`                    | Used when source is absent         |

If no rule matches → `RuntimeException`:
```
Cannot resolve parameter '$name' in App\Controller::method() — add an annotation or a default value
```

---

## Type Casting

Scalar annotations (`#[PathVariable]`, `#[RequestParam]`, `#[RequestHeader]`)
share the same casting logic. The value arrives as a `string` from the HTTP layer
and is cast to the PHP parameter type before injection:

| PHP type            | Valid input                              | Cast behavior                                                    | On failure |
|---------------------|------------------------------------------|------------------------------------------------------------------|------------|
| `int`               | Any numeric string without decimal point | `FILTER_VALIDATE_INT` → `(int)`                                  | `400`      |
| `float`             | Any numeric string                       | `FILTER_VALIDATE_FLOAT` → `(float)`                              | `400`      |
| `bool`              | `true` `false` `1` `0` `yes` `no` `on` `off` | `FILTER_VALIDATE_BOOLEAN`                                    | `400`      |
| `string`            | Any                                      | Raw value as-is                                                  | Never      |
| `array`             | Bracket notation `key[]=v`              | Must already be array (PHP parsed), scalar rejected              | `400`      |
| `BackedEnum`        | Enum case value                          | `Enum::from()` — int-backed: pre-cast string→int                 | `400`      |
| `DateTimeImmutable` | ISO 8601 string                          | `new DateTimeImmutable(string)` — empty string rejected          | `400`      |
| `DateTime`          | ISO 8601 string                          | `new DateTime(string)` — empty string rejected                   | `400`      |
| `BcMath\Number`     | Numeric string *(ext-bcmath)*            | `is_numeric()` check → `new BcMath\Number(string)`               | `400`      |
| `Decimal\Decimal`   | Numeric string *(ext-decimal)*           | `is_numeric()` check → `new Decimal\Decimal(string)`             | `400`      |
| Union type          | —                                        | `LogicException` thrown at startup, not at request time          | Startup    |

> **Precision note:** `BcMath\Number` and `Decimal\Decimal` always receive the value
> as a `string`, never as a PHP `float`. This prevents IEEE 754 precision loss —
> `"1.1"` stays `"1.1"`, not `"1.0999999999999999111821580..."`.

---

## Optional vs Required

A parameter bound to an HTTP source is **required** by default.
It becomes optional when:

```php
?int   $page          // nullable type     — null when source is absent
int    $page = 1      // default value     — 1 when source is absent
?int   $page = null   // nullable + default — null when source is absent
```

The check order when the source value is absent:
1. Has a PHP default value → return it
2. Type is nullable → return `null`
3. Otherwise → `400 Bad Request`

---

## Empty String ≠ Absent

For all typed parameters, an empty string is **not** equivalent to an absent value.
It is treated as a present-but-invalid value:

```
?page=         → value is "" (empty string) → 400 for int / float / bool / enum / date
?page not sent → value is absent           → null (nullable) or default or 400
```

The only exception is `string` — an empty string is a valid string value.

---

## Union Types

Union and intersection types on HTTP-bound parameters are not supported.
The resolver detects them at runtime and throws a `LogicException` immediately
(before any cast or HTTP response), so the misconfiguration is visible during
development or startup:

```php
// ✗ throws LogicException at request time:
public function action(#[RequestParam] int|string $value): void {}

// ✓ use a single type; validate manually if needed:
public function action(#[RequestParam] string $value): void {}
```

---

## Quick Example

```php
#[RequestMapping('api/v1')]
class OrderController extends Controller
{
    #[GetMapping('orders/{id:\d+}')]
    public function show(
        #[PathVariable]  int                 $id,
        #[RequestHeader] string              $authorization,
    ): ResponseEntity { ... }

    #[GetMapping('orders')]
    public function list(
        #[RequestParam]  int                 $page    = 1,
        #[RequestParam]  int                 $limit   = 20,
        #[RequestParam]  ?string             $search  = null,
        #[RequestParam]  ?OrderStatus        $status  = null,
        #[RequestParam]  ?\DateTimeImmutable $from    = null,
        #[RequestParam]  array               $ids     = [],
    ): ResponseEntity { ... }

    #[PostMapping('orders')]
    public function create(
        #[RequestBody]   CreateOrderDto      $dto,
    ): ResponseEntity { ... }

    #[PostMapping('orders/{id:\d+}/attachment')]
    public function attach(
        #[PathVariable]  int                 $id,
        #[RequestFile('file')] array         $file,
    ): ResponseEntity { ... }
}
```

---

## Documentation Index

### Scalar sources — value from URL or headers

| # | File | Contents |
|---|------|----------|
| 01 | [01-path-variable.md](01-path-variable.md) | `#[PathVariable]` — URL path segments, route patterns, all types, nullable, default, custom name, auto-match |
| 02 | [02-request-param.md](02-request-param.md) | `#[RequestParam]` — query string parameters, array bracket notation, all types, nullable, default, custom name |
| 04 | [04-request-header.md](04-request-header.md) | `#[RequestHeader]` — HTTP headers, name auto-conversion, all scalar types |

### Body sources — value from request body

| # | File | Contents |
|---|------|----------|
| 03 | [03-request-body.md](03-request-body.md) | `#[RequestBody]` — string / array / object / plain DTO / nested DTO / variadic collection |
| 07 | [07-request-json-form-xml.md](07-request-json-form-xml.md) | `#[RequestJson]` `#[RequestForm]` `#[RequestXml]` — forced format, vs auto-detect |

### Structured sources

| # | File | Contents |
|---|------|----------|
| 05 | [05-request-file.md](05-request-file.md) | `#[RequestFile]` — multipart uploads, single file info, file contents, all files map |
| 06 | [06-request-query.md](06-request-query.md) | `#[RequestQuery]` — full query string mapped to a DTO |

### Validation

| # | File | Contents |
|---|------|----------|
| 08 | [08-validation.md](08-validation.md) | `#[Valid]` + all constraints — Required, NotBlank, Min, Max, Size, Email, Uuid, etc. |
