# `#[RequestHeader]`

Binds an HTTP request header value to a controller method parameter. Header names in HTTP
are case-insensitive by spec, so `Authorization`, `authorization`, and `AUTHORIZATION` all
refer to the same header — the framework handles this transparently.

```php
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestHeader;
```

---

## Syntax

```php
#[RequestHeader]               // header name derived from PHP parameter name (auto-conversion)
#[RequestHeader('X-Custom')]   // header name = explicit value (exact, no conversion)
```

---

## Rules

1. Header lookup is case-insensitive (HTTP/1.1 spec, RFC 7230).
2. If `name` is omitted, the PHP parameter name is converted to a header name automatically (see [Auto Name Conversion](#auto-name-conversion)).
3. An explicit `name` is used as-is with no transformation.
4. Required by default — a missing header throws `400 Bad Request`.
5. Nullable (`?T`) or a default value makes the parameter optional.
6. Same type casting as `#[RequestParam]` applies (int, float, bool, enum, date, etc.).

---

## Auto Name Conversion

When no explicit name is given, the PHP parameter name is converted to a standard HTTP
header name. Both **snake_case** and **camelCase** parameter naming styles are supported:

```
PHP parameter name   →   header looked up
────────────────────────────────────────────────────
$authorization       →   authorization          (Authorization)
$x_request_id        →   x-request-id           (X-Request-Id)
$xRequestId          →   x-request-id           (X-Request-Id)
$xRequestedWith      →   x-requested-with       (X-Requested-With)
$x_requested_with    →   x-requested-with       (X-Requested-With)
$contentType         →   content-type           (Content-Type)
```

The conversion pipeline applied to the PHP parameter name:

1. **`Tool::camelToKebab()`** — inserts `-` before each uppercase letter and lowercases:
   `xRequestedWith` → `x-requested-with`
2. **`str_replace('_', '-')`** — replaces remaining underscores with dashes:
   `x_requested_with` → `x-requested-with`

Both steps are always applied, which means either naming style produces the same result:

```php
// Both resolve to the same header: X-Requested-With
#[RequestHeader] string $xRequestedWith
#[RequestHeader] string $x_requested_with
```

Since HTTP header lookup is case-insensitive, the lowercase result (`x-requested-with`)
matches `X-Requested-With`, `x-requested-with`, and any other casing the client sends.

---

## Examples

```php
// snake_case style
#[RequestHeader] string $x_requested_with    // → X-Requested-With

// camelCase style — equivalent result
#[RequestHeader] string $xRequestedWith      // → X-Requested-With

// Single-word — no conversion needed
#[RequestHeader] string $authorization       // → Authorization

// Explicit name — no auto-conversion applied
#[RequestHeader('X-Trace-Id')] string $traceId

// Optional header
#[RequestHeader('X-Forwarded-For')] ?string $forwardedFor = null

// Typed header
#[RequestHeader('X-Retry-Count')] int $retryCount
```

---

## Error Messages

The header name in the error message is whatever the resolver looked up — that means **lowercase** when auto-converted from a PHP parameter name, or **exactly the string you passed** when given explicitly to the attribute.

| Situation                            | HTTP | Message                                                          |
|--------------------------------------|------|------------------------------------------------------------------|
| Missing required (auto-converted)    | 400  | `Required header 'x-requested-with' is missing`                  |
| Missing required (explicit name)     | 400  | `Required header 'X-Trace-Id' is missing`                        |
| Invalid int (explicit name)          | 400  | `Header 'X-Retry-Count' must be an integer, got 'abc'`           |

---

## Related

| Topic | File |
|-------|------|
| Overview — resolution priority, type casting table | [00-overview.md](00-overview.md) |
| Query string parameters | [02-request-param.md](02-request-param.md) |
| URL path segments | [01-path-variable.md](01-path-variable.md) |
