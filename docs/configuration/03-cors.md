# CORS

Winter has two layers of CORS configuration, both modelled after Spring's `@CrossOrigin`:

1. **Global** — a `WebConfigurer` the scan finds. Applied to every response, including 404, 405, and error responses.
2. **Per-route** — `#[CrossOrigin(...)]` attribute on a controller class or method. **Overrides** the global config (does not merge with it) for that specific route.

The OPTIONS preflight is handled automatically by the Router before middleware or the controller runs.

---

## Global CORS

Global CORS is declared by any class extending `WebConfigurerAdapter` — there is no hook
to override on the application class, the scan finds the configurer wherever it lives:

```php
use Flytachi\Winter\Kernel\App\Config\CorsRegistry;
use Flytachi\Winter\Kernel\App\Config\WebConfigurerAdapter;

final class WebConfig extends WebConfigurerAdapter
{
    public function configureCors(CorsRegistry $cors): void
    {
        $cors->allowedOrigins('https://app.example.com')
             ->allowedHeaders('Content-Type', 'Authorization', 'X-Request-Id')
             ->exposeHeaders('X-Request-Id')
             ->allowCredentials()
             ->maxAge(3600);
    }
}
```

Each method takes a variadic list rather than an array, and returns the registry so the
calls chain. Touch nothing and no global CORS is applied.

### The settings

| Method | Default | Description |
|---|---|---|
| `allowedOrigins(...)` | none | Allowed origins. None → `Access-Control-Allow-Origin: *`. Several → reflects the matching request `Origin` and adds `Vary: Origin`. |
| `allowedHeaders(...)` | none | Request headers the browser may send. None → reflects `Access-Control-Request-Headers`. |
| `exposeHeaders(...)` | none | Response headers exposed to JavaScript. |
| `allowCredentials(bool)` | `false` | Sends `Access-Control-Allow-Credentials: true`. **Requires explicit origins** — incompatible with the wildcard `*`. |
| `maxAge(int)` | `0` | Preflight cache TTL (`Access-Control-Max-Age`). `0` = header not sent. |
| `vary(...)` | none | Extra `Vary` header values appended to the response. |

When no configurer touches the registry, no global CORS is sent — every response is
same-origin only.

Source: `src/Http/Cors.php`.

---

## When global headers are emitted

Global CORS headers are written **before** route dispatch, which means they appear on:

- successful responses
- `404 Not Found`
- `405 Method Not Allowed`
- error responses (`5xx`, `4xx` thrown from controllers / middleware)

This is intentional: a browser making a CORS preflight to a non-existent route still needs the CORS headers to be present, otherwise the browser hides the actual status from JavaScript.

For details on the request pipeline, see [`../architecture/01-routing.md`](../architecture/01-routing.md#request-handling-pipeline).

---

## OPTIONS preflight

When the browser sends an `OPTIONS` preflight, the Router:

1. Probes the dispatcher with the value of `Access-Control-Request-Method` to find the **real** target route.
2. Reads `#[CrossOrigin]` from the matched controller class / method, if any.
3. Sends `204 No Content` with the appropriate `Access-Control-Allow-*` headers — **before** any middleware or controller code runs.

Application code never sees an `OPTIONS` request that has a registered handler — it is short-circuited automatically.

---

## Per-route — `#[CrossOrigin]`

`#[CrossOrigin]` overrides the global config for the routes it covers. Method-level wins over class-level; class-level wins over global.

```php
use Flytachi\Winter\Kernel\Route\Annotation\CrossOrigin;
use Flytachi\Winter\Kernel\Route\Annotation\{RequestMapping, GetMapping};
use Flytachi\Winter\Kernel\Http\Stereotype\Controller;

#[RequestMapping('admin')]
#[CrossOrigin(
    origins: ['https://admin.example.com'],
    credentials: true,
)]
class AdminController extends Controller
{
    #[GetMapping('stats')]
    public function stats(): ResponseEntity { ... }   // ← admin.example.com only

    #[GetMapping('feed')]
    #[CrossOrigin(
        origins: ['https://partner.example.com'],     // ← partner-only feed
        maxAge:  3600,
    )]
    public function feed(): ResponseEntity { ... }
}
```

### Constructor parameters

Same shape as `Cors::configure()`:

| Parameter | Type | Default |
|---|---|---|
| `origins` | `string[]` | `[]` (wildcard) |
| `allowHeaders` | `string[]` | `[]` (reflect `Access-Control-Request-Headers`) |
| `exposeHeaders` | `string[]` | `[]` |
| `credentials` | `bool` | `false` |
| `maxAge` | `int` | `0` |
| `vary` | `string[]` | `[]` |

Source: `src/Route/Annotation/CrossOrigin.php`.

### Replaces — does not merge

This is the key difference from some other frameworks: `#[CrossOrigin]` **fully replaces** the global config for that route. If the global allows `Authorization` and `Content-Type`, and the method-level `#[CrossOrigin]` only lists `origins`, then `Authorization` is **not** allowed on that route.

Practical rule: when adding per-route CORS, copy the relevant headers from the global config so you don't accidentally drop them.

---

## Credentials + wildcard

`credentials: true` is incompatible with `origins: []` (which resolves to `*`). Browsers will reject the response even though the server sent it. To use credentials you must list every allowed origin explicitly:

```php
// ✗ Will be silently rejected by browsers:
Cors::configure(credentials: true);  // origins is empty → '*' + Allow-Credentials

// ✓ Explicit origins:
Cors::configure(
    origins:     ['https://app.example.com', 'https://admin.example.com'],
    credentials: true,
);
```

When multiple origins are configured, the framework reflects the matching `Origin` header back into `Access-Control-Allow-Origin` and adds `Vary: Origin` so intermediary caches do not mix responses for different origins.

---

## Quick reference — common setups

### Public read-only API

```php
Cors::configure(
    origins:      [],       // any origin
    allowHeaders: ['Content-Type'],
    maxAge:       86400,
);
```

### Authenticated single-page app

```php
Cors::configure(
    origins:      ['https://app.example.com'],
    allowHeaders: ['Content-Type', 'Authorization'],
    credentials:  true,
    maxAge:       3600,
);
```

### Multi-tenant — different origins on different routes

Set a permissive global, then tighten per-controller:

```php
// Global default — any origin, no credentials
Cors::configure(origins: [], allowHeaders: ['Content-Type']);

// Admin section — credentialed, narrow origin
#[CrossOrigin(
    origins:     ['https://admin.example.com'],
    allowHeaders:['Content-Type', 'Authorization'],
    credentials: true,
)]
class AdminController extends Controller { ... }
```

---

## Related

| Topic | File |
|---|---|
| Routing pipeline & where CORS fits | [../architecture/01-routing.md](../architecture/01-routing.md#cors) |
| Bootstrap hooks order | [01-kernel.md](01-kernel.md#boot-order) |
