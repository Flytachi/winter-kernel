# Exception Handling

---

## Overview

The framework uses a **`@ControllerAdvice`** pattern (Spring Boot terminology) to map
`Throwable` instances to HTTP responses. All unhandled exceptions bubble up to
`ExceptionWrapper::wrap()`, which routes them to the most specific registered handler.

---

## Default behavior — `ExceptionResponseBase`

Without any custom handler, every exception is handled by `ExceptionResponseBase`:

- HTTP code comes from `(int) $throwable->getCode()`, falling back to **500** when the code is not a valid HTTP status.
- Response format (JSON / XML / HTML) matches the client's `Accept` header — same content negotiation as `ResponseEntity`.
- In **DEBUG mode** (`DEBUG=true`): rich HTML page with stack trace, or JSON with full trace + debug metadata.
- In **production**: minimal error page or JSON with `code` + `message` only.
- `ValidationException` automatically adds an `errors` map to the JSON body.

---

## Exception hierarchy

### `ResponseException` — expected HTTP errors

Throw from anywhere — controllers, services, middleware. The Router catches it and sends an HTTP response.

```php
use Flytachi\Winter\K2\Http\Response\ResponseException;
use Flytachi\Winter\Base\HttpCode;

throw new ResponseException('User not found', HttpCode::NOT_FOUND);
ResponseException::throw('Forbidden', HttpCode::FORBIDDEN);  // static helper

// With extra response headers:
throw (new ResponseException('Rate limit exceeded', HttpCode::TOO_MANY_REQUESTS))
    ->withHeader('Retry-After', '60');
```

Default code: **400 Bad Request**. Logged at `warning` level.

### `ClientError` — business / domain errors caused by the caller

```php
use Flytachi\Winter\K2\Exception\ClientError;

throw new ClientError('Email already taken');
ClientError::throw('Email already taken', HttpCode::UNPROCESSABLE_ENTITY);
```

Default code: **409 Conflict**. Logged at `warning` level.

### `ServerError` — unexpected infrastructure or application failures

```php
use Flytachi\Winter\K2\Exception\ServerError;

throw new ServerError('Payment gateway timeout');
ServerError::throw('Database connection failed');
```

Default code: **500 Internal Server Error**. Logged at `error` level.

### `Error` — generic HTTP-aware exception with auto log-level

Maps the HTTP code to a log level automatically:

| Code range | Log level  |
|------------|------------|
| 4xx        | `warning`  |
| 5xx / 520+ | `error`    |
| Unknown    | `critical` |
| Other      | `notice`   |

```php
use Flytachi\Winter\K2\Exception\Error;

throw new Error('Not implemented', HttpCode::NOT_IMPLEMENTED);
Error::throw('Method not allowed', HttpCode::METHOD_NOT_ALLOWED);
```

Default code: **520** (Unknown Error). Useful when callers do not know whether an error is a 4xx or 5xx at the call site.

### `KernelError` — framework-internal invariant violations

Reserved for bugs in the kernel itself (misconfiguration, impossible state):

```php
use Flytachi\Winter\K2\Exception\KernelError;

throw new KernelError('Router not initialized before handle()');
```

Default code: **500**. Logged at `emergency` level — the highest severity.

### `MiddlewareException` — abort from middleware

See the [Middleware docs](02-middleware.md) for details. Default code: **401**.

---

## Log levels

`Router::handle()` logs every exception before sending the error response. The level is determined by the exception type:

- Implements `ExceptionLogLevel` → calls `$e->getLogLevel()` — the exception declares its own level
- Anything else → `error` (including plain `\RuntimeException`, `\LogicException`, etc.)

All K2 exceptions (`ResponseException`, `ClientError`, `ServerError`, `Error`, `KernelError`, `MiddlewareException`) implement `ExceptionLogLevel`.

---

## Custom exception handlers — `#[AdviceException]`

Create a class that:
1. Implements `ResponseExceptionInterface`
2. Extends `ExceptionResponseBase` (optional, but provides `contentData()`, `contentHtml()`, `validationRequests()`, `debugData()` helpers)
3. Carries the `#[AdviceException]` attribute

```php
use Flytachi\Winter\K2\Http\Response\{AdviceException, ExceptionResponseBase};

#[AdviceException(MyDomainException::class)]
class MyDomainExceptionHandler extends ExceptionResponseBase
{
    protected function contentData(): array
    {
        return [
            'code'    => $this->throwable->getCode(),
            'error'   => 'domain_error',
            'detail'  => $this->throwable->getMessage(),
            'errors'  => $this->validationRequests(),  // [] unless it's a ValidationException
        ] + $this->debugData();
    }
}
```

**Catch-all handler** (no exception class argument — matched last):

```php
#[AdviceException]
class GlobalExceptionHandler extends ExceptionResponseBase { ... }
```

**Multiple exception types** on one handler:

```php
#[AdviceException(NotFoundException::class, GoneException::class)]
class NotFoundHandler extends ExceptionResponseBase { ... }
```

### Resolution order

1. Specific handlers (with exception class names) are tried first — most specific match wins.
2. Catch-all handlers (no class arguments) are tried last.
3. If no handler matches, `ExceptionResponseBase` is used as the fallback.

---

## `ExceptionResponseBase` — override points

| Method | Purpose |
|--------|---------|
| `contentData(): array` | JSON / XML body for non-HTML responses |
| `contentHtml(): string` | Full HTML body for `Accept: text/html` requests |

Helpers available in subclasses (`protected` unless noted):

| Helper | Returns |
|--------|---------|
| `$this->throwable` | The original `Throwable` (protected property) |
| `$this->httpCode` | `HttpCode` resolved from the throwable's code (protected property) |
| `validationRequests(): array` | `$errors` map if the throwable is a `ValidationException`, otherwise `[]` |
| `debugData(): array` | Debug metadata (`date`, `sapi`, `memory`, `time`, `exception`) — empty in production |
| `addHeader(string $key, string $value): void` | Append a response header. Inherited `final public` from `ResponseTrait`; called automatically for every `ExceptionHeader` returned by the throwable's `getExtraHeaders()`. |
| `getHeader(): array` | Return all headers accumulated so far (inherited from `ResponseTrait`). |

---

## Bootstrap

`ExceptionWrapper` is configured automatically when using `Router::resolve()` or `Router::fromScan()`. For manual setup:

```php
// Scan the project for #[AdviceException] handlers (lazy, on first error):
ExceptionWrapper::configure(__DIR__);

// Or inject pre-scanned handlers directly (from ExceptionCollector):
ExceptionWrapper::setHandlers($handlers);
```

When the route cache is loaded via `Router::fromCache()`, `ExceptionWrapper::configure()` is called so that handlers are discovered lazily on the first error.
