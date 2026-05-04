# Routing

Winter K2 uses a Spring Boot-style dual-mode router that works identically in Swoole coroutine mode and PHP-FPM.

---

## Route annotations (preferred)

Define routes via PHP attributes on controller classes and methods.

```php
use Flytachi\Winter\K2\Route\Annotation\{RequestMapping, GetMapping, PostMapping, PutMapping, PatchMapping, DeleteMapping};
use Flytachi\Winter\K2\Stereotype\Controller;

#[RequestMapping('users')]   // class-level prefix → /users
class UserController extends Controller
{
    #[GetMapping]             // GET /users
    public function index(): ResponseEntity { ... }

    #[GetMapping('{id}')]     // GET /users/{id}
    public function show(): ResponseEntity { ... }

    #[PostMapping]            // POST /users
    public function store(): ResponseEntity { ... }

    #[PutMapping('{id}')]     // PUT /users/{id}
    public function update(): ResponseEntity { ... }

    #[PatchMapping('{id}')]   // PATCH /users/{id}
    public function patch(): ResponseEntity { ... }

    #[DeleteMapping('{id}')]  // DELETE /users/{id}
    public function destroy(): ResponseEntity { ... }
}
```

`#[RequestMapping]` on the class sets the URL prefix. `#[RequestMapping]` on a method matches any HTTP method.

All method-mapping attributes are **repeatable** — a single method can handle multiple paths:

```php
#[GetMapping('search')]
#[GetMapping('filter')]
public function search(): ResponseEntity { ... }
```

### Path variables

Use `{name}` or `{name:regex}` in the path string:

```php
#[GetMapping('{id:\d+}')]        // digits only
#[GetMapping('{slug:[a-z-]+}')]  // lowercase slug
```

Path variable values are injected into the controller method via `#[PathVariable]` — see the request docs.

---

## Manual route registration

```php
$router = new Router();

$router->get('/ping',           fn($req, $res, $p) => ResponseEntity::ok('pong'));
$router->post('/users',         [UserController::class, 'store']);
$router->put('/users/{id}',     [UserController::class, 'update']);
$router->patch('/users/{id}',   [UserController::class, 'patch']);
$router->delete('/users/{id}',  [UserController::class, 'destroy']);
$router->options('/users',      [UserController::class, 'preflight']);

// Full add() signature — with middleware and per-route CORS:
$router->add('GET', '/admin/stats', [AdminController::class, 'stats'], [
    ['class' => AuthMiddleware::class, 'args' => []],
]);
```

Registering the same method + path combination twice throws `RuntimeException` immediately.

---

## Factory methods

### `Router::resolve()` — recommended entry point

```php
$router = Router::resolve(Kernel::$pathRoot);
```

| Condition | Behaviour |
|-----------|-----------|
| `DEBUG=true` | Always scans — cache is never read or written |
| `DEBUG=false`, cache exists | Loads from `mapping.php` (no reflection) |
| `DEBUG=false`, no cache | Scans project, writes cache for next boot |

### `Router::fromScan()` — always scan

```php
$router = Router::fromScan(Kernel::$pathRoot, exclude: ['legacy/']);
```

Runs a unified `Scanner` pass that discovers:
- Controller route mappings (`MappingCollector`)
- `#[AdviceException]` exception handlers (`ExceptionCollector`)
- Plugin routes (from each registered plugin's `src/` directory)
- Health actuator routes (when `Health::getConfig()` is set)

### `Router::fromCache()` — load from cache file

```php
$router = Router::fromCache(Router::cachePath());
```

Skips all filesystem scanning and reflection. Closure-based handlers (Health actuator) are excluded from the cache and re-registered from the current Health config on load.

### Route cache

`dumpCache()` writes to `Kernel::$pathStorageVolatile/mapping.php` (accessible via `Router::cachePath()`). Closure handlers are skipped (not serializable). OPcache is invalidated automatically after writing.

---

## Request handling pipeline

`$router->handle(HttpRequest, HttpResponse)` runs a 13-step pipeline per request:

```
 1. Header::init()            — snapshot request headers into the static bag
 2. Locale::initFromRequest() — detect Accept-Language / locale cookie
 3. Swoole context            — stamp start time, method, uri in coroutine context
 4. Static file check         — short-circuit for existing files (GET only, Swoole)
 5. Global CORS headers       — applied before dispatch (covers 404 / 500 responses too)
 6. OPTIONS preflight         — responds 204 before handler invocation
 7. Route dispatch            — O(1) static map → chunked regex dynamic scan
 8. Per-route #[CrossOrigin]  — overrides global CORS if present
 9. Middleware before()       — run in declaration order
10. Controller method         — resolved via ReflectionCache + ParameterResolver
11. Middleware after()        — run in reverse order
12. Response serialise        — Sendable::send() or ResponseEntity::ok()->send()
13. Error handling            — ExceptionWrapper maps Throwable → HTTP response
```

All errors — including middleware abort and validation failures — are caught at step 13.

---

## Static file serving

Required for Swoole (unlike FPM+nginx, Swoole does not serve static files natively):

```php
$router->static(Kernel::$pathPublic);
```

On a `GET` request, the router checks whether the URI maps to an existing file under `$publicDir`. If found, the file is served directly with auto-detected MIME type and a 24-hour `Cache-Control: public, max-age=86400`. No route matching occurs.

---

## CORS

### Global CORS

Call once in bootstrap **before** `Router::resolve()`:

```php
use Flytachi\Winter\K2\Http\Cors;

Cors::configure(
    origins:       ['https://app.example.com'],
    allowHeaders:  ['Authorization', 'Content-Type'],
    exposeHeaders: ['X-Request-Id'],
    credentials:   true,
    maxAge:        3600,
);
```

| Parameter       | Default | Description |
|-----------------|---------|-------------|
| `origins`       | `[]`    | Allowed origins. Empty → `Access-Control-Allow-Origin: *`. Multiple origins → reflects the matching request `Origin` and adds `Vary: Origin`. |
| `allowHeaders`  | `[]`    | Allowed request headers. Empty → reflects `Access-Control-Request-Headers`. |
| `exposeHeaders` | `[]`    | Response headers exposed to JavaScript. |
| `credentials`   | `false` | Sends `Access-Control-Allow-Credentials: true`. Requires explicit origins (incompatible with `*`). |
| `maxAge`        | `0`     | Preflight cache TTL (`Access-Control-Max-Age`). `0` = not sent. |
| `vary`          | `[]`    | Extra `Vary` header values. |

Global CORS headers are written **before** route dispatch — they appear on 404, 405, and error responses.

### Per-route CORS — `#[CrossOrigin]`

Placed on a controller class or method, `#[CrossOrigin]` **overrides** (does not merge with) the global config:

```php
use Flytachi\Winter\K2\Route\Annotation\CrossOrigin;

// Entire controller
#[CrossOrigin(origins: ['https://admin.example.com'], credentials: true)]
class AdminController extends Controller { ... }

// Single method
#[CrossOrigin(origins: ['https://partner.example.com'], maxAge: 3600)]
#[GetMapping('feed')]
public function feed(): ResponseEntity { ... }
```

Same parameters as `Cors::configure()`. During an OPTIONS preflight the router probes the dispatcher with the browser's intended method to find the matching route's `#[CrossOrigin]` before responding 204.

---

## Dispatch internals

The `Dispatcher` uses two strategies:

- **Static routes** (no `{variable}` segments) — O(1) hash map lookup per method.
- **Dynamic routes** (contain `{variable}` segments) — compiled into chunked regex groups.

`RouteResult` carries one of three statuses:

| Status | Meaning |
|--------|---------|
| `FOUND` | Handler found; extracted path params available |
| `METHOD_NOT_ALLOWED` | Path matches but method does not; `allowedMethods` populated |
| `NOT_FOUND` | No path match |

405 responses automatically include an `Allow` header listing the registered methods for that path.
