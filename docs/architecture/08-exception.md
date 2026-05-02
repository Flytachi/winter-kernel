# Exception Handling

## Overview

The framework uses the **`@ControllerAdvice`** pattern (Spring Boot terminology) to map
`Throwable` instances to HTTP responses. All unhandled exceptions bubble up to
`ExceptionWrapper::wrap()`, which picks the most specific registered handler.

## Default behavior

Without any custom handler, all exceptions map to `ExceptionResponseBase`:

| Exception type                    | HTTP code | Log level |
|-----------------------------------|-----------|-----------|
| `ResponseException` (4xx code)    | from `getCode()` | `warning` |
| Any other `Throwable` (code = 0)  | 500        | `error`   |
| `LogLevelException`               | from exception | custom level |

Response format (JSON / XML / HTML) is determined by the client's `Accept` header —
same content negotiation as `ResponseEntity`.

In DEBUG mode (`DEBUG=true`): rich HTML page with stack trace, or JSON with trace.
In production: minimal error page / JSON with `code` + `message`.

`ValidationException` automatically adds an `errors` map to the JSON body.

## ResponseException

Use `ResponseException` for expected HTTP errors:

```php
use Flytachi\Winter\K2\Http\Response\ResponseException;
use Flytachi\Winter\Base\HttpCode;

throw new ResponseException('User not found', HttpCode::NOT_FOUND);

// With extra response headers
throw (new ResponseException('Rate limit exceeded', HttpCode::TOO_MANY_REQUESTS))
    ->withHeader('Retry-After', '60');
```

## Custom handlers (#[AdviceException])

Create a class that:
1. Implements `ResponseExceptionInterface`
2. Extends `ExceptionResponseBase` (optional but recommended)
3. Carries `#[AdviceException]`

```php
use Flytachi\Winter\K2\Http\Response\AdviceException;
use Flytachi\Winter\K2\Http\Response\ExceptionResponseBase;
use Flytachi\Winter\Base\HttpCode;

#[AdviceException(MyDomainException::class)]
class MyDomainExceptionHandler extends ExceptionResponseBase
{
    protected function contentData(): array
    {
        return [
            'code'    => $this->throwable->getCode(),
            'error'   => 'domain_error',
            'detail'  => $this->throwable->getMessage(),
            // include validation errors if the exception carries them:
            'errors'  => $this->validationRequests(),  // [] when not a ValidationException
        ] + $this->debugData();
    }
}
```

`ExceptionWrapper` discovers handlers by scanning the project directory at first use
(vendor excluded). Handlers for specific exception classes are tried before catch-alls.

**Catch-all handler** (no exception class argument):

```php
#[AdviceException]
class GlobalExceptionHandler extends ExceptionResponseBase { ... }
```

## Bootstrap

`ExceptionWrapper` is configured automatically when using `Router::resolve()` or
`Router::fromScan()`. For manual setup:

```php
ExceptionWrapper::configure(__DIR__);   // scan project once
// or inject pre-scanned handlers:
ExceptionWrapper::setHandlers($handlers);
```

## Log levels

The framework logs exceptions automatically in `Router::handle()`:

- `LogLevelException` → custom level declared on the exception
- `ResponseException` with 4xx code → `warning`
- Everything else → `error`

Override by implementing `LogLevelException` on your custom exception class.
