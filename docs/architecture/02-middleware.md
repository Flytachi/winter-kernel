# Middleware

Middleware intercepts requests before and after the controller method runs.

---

## Creating a middleware

Extend `Flytachi\Winter\Kernel\Http\Stereotype\Middleware` and override `before()`, `after()`, or both.
Both methods have default no-op implementations — override only what you need.

```php
use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;
use Flytachi\Winter\Kernel\Http\Contracts\{HttpRequest, HttpResponse};
use Flytachi\Winter\Kernel\Http\Middleware\MiddlewareException;

class AuthMiddleware extends Middleware
{
    public function before(HttpRequest $request, HttpResponse $response): void
    {
        $token = $request->getHeader('Authorization');
        if (!$this->validate($token)) {
            throw new MiddlewareException('Unauthorized');  // 401 by default
        }
    }
}
```

---

## Applying middleware

### Via attribute (preferred)

Place the middleware class as an attribute on a **controller class** or **method**:

```php
#[AuthMiddleware]                     // applies to every route in this controller
class UserController extends Controller
{
    #[AdminMiddleware]                // stacked on top of AuthMiddleware for this route only
    #[GetMapping('admin/stats')]
    public function stats(): ResponseEntity { ... }

    #[GetMapping]                     // only AuthMiddleware applies here
    public function index(): ResponseEntity { ... }
}
```

Class-level and method-level middleware are discovered by `MappingCollector` during the scan pass.
Class-level middleware is applied to every route on that controller.

### Via manual registration

```php
$router->add('GET', '/api/data', [DataController::class, 'index'], [
    ['class' => AuthMiddleware::class,      'args' => []],
    ['class' => RateLimitMiddleware::class, 'args' => ['limit' => 100]],
]);
```

`args` are forwarded to the middleware constructor via `Container::make()`.

---

## Execution order

Given middlewares `[A, B, C]` on a route:

```
Request
  → A::before()   ← declaration order
  → B::before()
  → C::before()
     controller method
  ← C::after()    ← reverse order
  ← B::after()
  ← A::after()
Response
```

`before()` runs in declaration order; `after()` runs in **reverse** declaration order.

---

## `before(HttpRequest, HttpResponse): void`

- Called with the request and response objects before the controller.
- Throw `MiddlewareException` (or any `ResponseException`) to abort the request — no controller code runs, and the remaining middlewares' `before()` are skipped.
- Do **not** call `$response->end()` directly — let exceptions produce the error response.

## `after(mixed $result): mixed`

- Called after the controller method returns (in reverse middleware order).
- Receives the return value of the previous stage (controller result or prior `after()` return).
- Return the value unchanged to pass it through, or replace it with a transformed result.

```php
class TimingMiddleware extends Middleware
{
    private float $start;

    public function before(HttpRequest $request, HttpResponse $response): void
    {
        $this->start = microtime(true);
    }

    public function after(mixed $result): mixed
    {
        $elapsed = round((microtime(true) - $this->start) * 1000, 2);
        if ($result instanceof ResponseEntity) {
            return $result->header('X-Response-Time', $elapsed . 'ms');
        }
        return $result;
    }
}
```

---

## `MiddlewareException`

Shorthand for aborting a request with a specific HTTP status from inside middleware:

```php
use Flytachi\Winter\Kernel\Http\Middleware\MiddlewareException;
use Flytachi\Winter\Base\HttpCode;

throw new MiddlewareException('Token expired');                           // 401 Unauthorized
throw new MiddlewareException('Forbidden', HttpCode::FORBIDDEN);         // 403
MiddlewareException::throw('Rate limited', HttpCode::TOO_MANY_REQUESTS); // static helper

// With extra response header:
throw (new MiddlewareException('Rate limited', HttpCode::TOO_MANY_REQUESTS))
    ->withHeader('Retry-After', '60');
```

`MiddlewareException` extends `ResponseException`. It implements `ExceptionLogLevel` and is logged at `warning` level. The default HTTP code is **401 Unauthorized**.

---

## Built-in middlewares

### `ClientTimezoneMiddleware`

Applies the client's IANA timezone for the duration of the request. The value is read from `HttpRequest::getClientTimezone()`, which parses the `Timezone` or `X-Timezone` header and validates it against `timezone_identifiers_list()`. When no valid timezone is supplied, `before()` falls back to `env('TIME_ZONE', 'UTC')`.

```php
use Flytachi\Winter\Kernel\Http\Middleware\ClientTimezoneMiddleware;

#[ClientTimezoneMiddleware]
class ReportController extends Controller { ... }
```

It stores the timezone in two places, and the difference decides which of your code is safe:

| | Where | Safe under concurrency |
|---|---|---|
| `Timezone::current()` | coroutine-local (`RequestLocal`) | **yes** |
| `date_default_timezone_set()` | PHP engine global | only while concurrent requests share one zone |

`Timezone` is the source of truth. Everything the framework does on the request's behalf reads it — including the timezone of the database session, so a pooled connection never carries the previous user's zone into your query.

The engine global is set as a convenience for code that has not been adapted, and it is genuinely shared: a Swoole worker runs many requests as coroutines in one process, so a request that sets its zone and then waits on I/O can resume to find another request's value in place. Measured, not reasoned — a request from `Asia/Tashkent` yielded, a request from `Europe/London` set its own, and the first read `Europe/London` on resume. No library can fix that; it is where PHP keeps its default.

So pass the zone explicitly wherever the answer must belong to the requesting user:

```php
use Flytachi\Winter\Kernel\Localization\Timezone;

$when = new \DateTimeImmutable('now', new \DateTimeZone(Timezone::current()));
```

**Swoole caveat.** `after()` is not invoked when the handler throws — the `catch (\Throwable)` in `Router::dispatch` sits outside the after-loop. The coroutine-local value dies with the coroutine either way, but the engine global keeps the client's value until the next request through this middleware resets it — one more reason not to lean on it.

See [Client timezone detection](../configuration/01-kernel.md#client-timezone-detection) for the request-side contract and the no-mutation usage pattern.

---

## Dependency injection

Middleware instances are created via `Container::make()`, so `#[Autowired]` constructor injection works automatically:

```php
class AuthMiddleware extends Middleware
{
    public function __construct(
        #[Autowired] private readonly TokenService $tokenService,
    ) {}

    public function before(HttpRequest $request, HttpResponse $response): void
    {
        $token = $request->getHeader('Authorization') ?? '';
        if (!$this->tokenService->validate($token)) {
            throw new MiddlewareException('Invalid or missing token');
        }
    }
}
```
