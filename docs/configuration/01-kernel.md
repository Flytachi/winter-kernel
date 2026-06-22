# Kernel & Bootstrap

The kernel resolves application paths, loads `.env`, configures logging and the thread runner, and exposes a small `Boot` class that you extend to wire up everything else.

This page describes the configuration knobs at the bottom of the stack. Logging, CORS, health, and plugins are documented separately — links at the end.

---

## The Boot class

Every application has one `Boot` class that extends `Flytachi\Winter\K2\BaseBoot`. It overrides only the hooks it needs and is called from one of four entry points (`web()`, `swoole()`, `cli()`, `executor()`).

```php
// bootstrap.php
use Flytachi\Winter\K2\BaseBoot;
use Flytachi\Winter\K2\Kernel;

class Boot extends BaseBoot
{
    protected static function configure(): void
    {
        Kernel::init(pathRoot: __DIR__);
    }
}
```

```php
// public/index.php
require __DIR__ . '/../bootstrap.php';
Boot::web();
```

The full hook list:

| Hook | When called | Purpose |
|---|---|---|
| `configure()` | first, before everything else | Call `Kernel::init(...)`, set paths and timezone. |
| `providers(Container $c)` | after DI scan | Manual DI bindings (factories, named scalars, service providers). |
| `channels()` | after `configure()` | Register custom log channels via `Kernel::channel('name')`. |
| `httpCors()` | before request dispatch | Call `Cors::configure(...)`. |
| `health()` | before request dispatch | Call `Health::configure(...)`. |
| `plugins()` | before route scan | Call `Plugin::registry(...)` for each plugin. |
| `swooleConfig()` | only in `swoole()` mode | Return options for `Swoole\Http\Server::set()`. |

All hooks except `swooleConfig()` are `protected` — override only what you need; defaults are no-ops or sane defaults.

---

## `Kernel::init()` parameters

```php
Kernel::init(
    pathRoot:            __DIR__,           // project root
    pathEnv:             __DIR__ . '/.env',
    pathPublic:          __DIR__ . '/public',
    pathResource:        __DIR__ . '/resources',
    pathStorage:         __DIR__ . '/storage',
    pathStorageLog:      __DIR__ . '/storage/logs',
    pathStorageCache:    __DIR__ . '/storage/cache',
    pathStorageRunnable: __DIR__ . '/storage/runnable',
    isTmpVolatile:       false,             // see "Volatile storage" below
);
```

Every parameter is **optional**. When `pathRoot` is omitted, it is derived from the calling location (`dirname(__DIR__, 5)`). All other paths are derived from `pathRoot` if not given:

| Param | Default |
|---|---|
| `pathEnv` | `$pathRoot . '/.env'` |
| `pathPublic` | `$pathRoot . '/public'` |
| `pathResource` | `$pathRoot . '/resources'` |
| `pathStorage` | `$pathRoot . '/storage'` |
| `pathStorageLog` | `$pathStorage . '/logs'` |
| `pathStorageCache` | `$pathStorage . '/cache'` |
| `pathStorageRunnable` | `$pathStorage . '/runnable'` |

After `init()`, all of these are available as public static properties on `Kernel`:

```php
Kernel::$pathRoot
Kernel::$pathEnv
Kernel::$pathPublic
Kernel::$pathResource
Kernel::$pathStorage
Kernel::$pathStorageLog
Kernel::$pathStorageCache
Kernel::$pathStorageRunnable
Kernel::$pathStorageVolatile
```

---

## Volatile storage

`pathStorageVolatile` is treated separately because it holds throwaway artefacts (route cache, mapping dump) that survive a single deployment but never need backup.

| `isTmpVolatile` | Resolves to |
|---|---|
| `false` | `$pathStorage . '/volatile'` — inside the project storage tree |
| `true` | `sys_get_temp_dir() . '/flytachi.winter.volatile.<basename>'` — per-host temp dir |

Use `true` for ephemeral containers (Docker, Kubernetes) where `/tmp` is fast and disposable. Use `false` for long-lived deployments where you want the route cache to persist with the rest of your storage.

`K2\Kernel::init()` passes `isTmpVolatile: false` by default; `KernelConfig::init()` defaults to `true` (the `K2\Kernel` wrapper flips it). Pass it explicitly if you want the other behaviour.

The directory is auto-created (`mkdir 0777 recursive`) on first call.

---

## Storage helpers

`Kernel` (via `KernelStore`) exposes three FileStorage caches keyed by a name. Each one lazily creates its parent directory and a `FileStorage` adapter:

```php
Kernel::store('users')      // → FileStorage at storage/cache/users
Kernel::runnable('jobs')    // → FileStorage at storage/runnable/jobs
Kernel::volatile('routes')  // → FileStorage at <volatile>/routes
```

| Method | Backing dir | Typical use |
|---|---|---|
| `Kernel::store($name, $hash = true)` | `pathStorageCache` | Application cache, computed values. |
| `Kernel::runnable($name, $hash = true)` | `pathStorageRunnable` | Job state, runtime tickets. |
| `Kernel::volatile($name, $hash = true)` | `pathStorageVolatile` | Throwaway artefacts (route map, dispatcher cache). |

The optional `$isHash` argument enables hashed file naming inside the storage (see `flytachi/file-store`).

`Kernel::showStorages()` / `showRunnable()` / `showVolatiles()` return arrays of all instances created so far.

---

## Environment variables

`.env` is loaded by `Dotenv::createImmutable($pathRoot)->safeLoad()` — a missing file is **not** an error. After load, values are read with the global `env('KEY', $default)` helper.

| Variable | Default | Effect |
|---|---|---|
| `DEBUG` | `false` | When truthy: `error_reporting=E_ALL`, `display_errors=1`. When falsy: silent error reporting. Also disables route cache writes. |
| `TIME_ZONE` | `UTC` | `date_default_timezone_set()` |
| `LOG_*` | — | See [02-logging.md](02-logging.md) |
| `WINTER_THREAD_RUNNER` | — | Override path to the executor binary used by `Thread`. |
| `WINTER_BINARY_PATH` | — | Custom binary path passed to `Thread::bindBinaryPath()`. |
| `WINTER_KEY` | — | Serialisation security key for `Thread` payloads (`Opis\Closure` mode). |

The constant `WINTER_STARTUP_TIME` is defined here if not already set elsewhere. For the request's scheme / host / port / base URL use the per-request methods on `HttpRequest` — see [Request addressing](#request-addressing) below.

### Thread runner discovery

`bindThread()` looks for the executor binary in this order:

1. `WINTER_THREAD_RUNNER` env var (absolute path)
2. `<pathRoot>/vendor/bin/wKernelExecutor`
3. `<pathRoot>/vendor/bin/wExecutor`
4. If none exists, the runner is left unbound — `Thread::dispatch()` will fail at runtime.

When `ext-shmop` is loaded, payload mode is set to `PAYLOAD_SHM` automatically (avoids fd conflicts in Swoole).

---

## Client timezone detection

`HttpRequest::getClientTimezone()` reads the `Timezone` or `X-Timezone` header from the incoming request, validates it against `timezone_identifiers_list()`, and returns the IANA name (or `null` if absent / invalid). The framework does **not** mutate `date_default_timezone_get()` on its own — consumers apply the value where they need it:

```php
$tz  = new DateTimeZone($request->getClientTimezone() ?? env('TIME_ZONE', 'UTC'));
$now = new DateTime('now', $tz);
```

For applications that want the timezone applied globally for the duration of the request, attach `ClientTimezoneMiddleware` to a controller or method:

```php
use Flytachi\Winter\K2\Http\Middleware\ClientTimezoneMiddleware;

#[ClientTimezoneMiddleware]
class ReportController extends Controller { ... }
```

The middleware calls `date_default_timezone_set()` in `before()` with the client value (or `env('TIME_ZONE', 'UTC')` as fallback) and restores the canonical default in `after()`.

**Swoole caveat.** `after()` does not run when the handler throws — `Router::dispatch` catches `Throwable` outside the after-loop. In a long-running worker, an unhandled exception leaves the global TZ at the client's value until the next request that passes through the middleware overwrites it. Apply the middleware uniformly across routes, or skip it and call `getClientTimezone()` explicitly inside handlers.

---

## Request addressing

`HttpRequest` exposes how the **client** addressed the server. Each method is computed per request — there is no boot-time constant — so the values are correct under FPM, Swoole, and any multi-host setup:

| Method | Returns |
|---|---|
| `getScheme(): string` | `'http'` or `'https'` |
| `getHost(): string` | hostname only, no port |
| `getPort(): int` | actual port (defaults: 80 / 443 by scheme) |
| `getBaseUrl(): string` | `scheme://host[:port]`, standard ports omitted |

Resolution order (each method short-circuits on the first hit):

1. `Forwarded` header (RFC 7239): `proto=`, `host=`, plus port part of `host=`.
2. `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port`.
3. Direct request data — `Host` header, `$_SERVER['HTTPS']` / `REQUEST_SCHEME` / `SERVER_PORT` (FPM), `$request->server['server_port']` (Swoole).
4. Safe defaults — `http`, `localhost`, scheme-derived port.

**Trust policy.** Proxy headers are honoured unconditionally — they take precedence over direct values. If the application is not behind a reverse proxy, strip these headers at the edge (nginx / cloud LB) before they reach PHP. Otherwise a client can spoof `X-Forwarded-Host` and force the backend to return URLs pointing at an attacker domain.

**Swoole SSL.** The Swoole HTTP server does not expose an `https`/`scheme` flag on the request object. Direct-SSL Swoole deployments must terminate TLS at a fronting proxy that sets `X-Forwarded-Proto: https`, otherwise `getScheme()` returns `'http'`.

```php
public function index(HttpRequest $request): ResponseEntity
{
    $callbackUrl = $request->getBaseUrl() . '/oauth/callback';
    // → "https://example.com/oauth/callback" or "http://localhost:8080/oauth/callback"
    ...
}
```

### Global access via `Header`

When the `HttpRequest` is not in scope — a service, a static helper, a job dispatched
mid-request — read the same values through the `Header` accessor. `Header::init($request)`
runs as the first step of the request pipeline and snapshots the origin alongside the
headers, so these getters need no `HttpRequest` argument:

```php
use Flytachi\Winter\K2\Http\Header;

Header::getBaseUrl();  // "https://example.com:8443"
Header::getScheme();   // "https"
Header::getHost();     // "example.com"   (host only, no port; IPv6 bracketed)
Header::getPort();     // 8443 (int)
```

Each getter returns `null` before `Header::init()` has run (e.g. outside a request, such as
in a console command). The snapshot is **coroutine-safe** under Swoole — stored in
`Coroutine::getContext()` so concurrent requests never see each other's origin — and a
process-wide static under FPM.

> `Header::getHost()` is the parsed host without port. The raw `Host` header is untouched and
> still available verbatim via `Header::get('Host')` (e.g. `"example.com:8443"`).

---

## Entry points

A complete request lifecycle from each entry point. Detailed pipelines live in [`../architecture/01-routing.md`](../architecture/01-routing.md#request-handling-pipeline).

### `Boot::web()` — FPM / nginx

```php
// public/index.php
require __DIR__ . '/../bootstrap.php';
Boot::web();
```

Reads the HTTP request from PHP superglobals, dispatches via `Router::resolve(Kernel::$pathRoot)` (cache-first), serves static files in `Kernel::$pathPublic`, then `exit(0)`. Default log channel is `http`.

### `Boot::swoole(host, port)` — Swoole HTTP server

```php
// server.php
require __DIR__ . '/bootstrap.php';
Boot::swoole(host: '0.0.0.0', port: 9501);
```

Single-process router, one route scan at startup, all requests share the same instance. Per-coroutine isolation for headers and locale. Override `swooleConfig()` to tune workers / `max_request` / SSL:

```php
protected static function swooleConfig(): array
{
    return [
        'worker_num'        => swoole_cpu_num() * 2,
        'max_request'       => 5000,
        'max_request_grace' => 500,
        'enable_coroutine'  => true,
    ];
}
```

Default log channel is `http`.

### `Boot::cli($argv)` — Console

```php
// call
require __DIR__ . '/bootstrap.php';
Boot::cli($argv);
```

Runs the console application (`Flytachi\Winter\Console\Core`). Default log channel is `cli`.

### `Boot::executor($argv)` — Thread / job runner

Invoked by the `wKernelExecutor` binary (you do not call this directly). Reads a serialised `Runnable` from stdin or shared memory, runs it, exits with the appropriate status code. Default log channel is `cli`.

CLI flags accepted by the binary:

| Flag | Purpose |
|---|---|
| `--namespace=App` | Process title namespace prefix |
| `--name=MyJob` | Override process title name (default: class short name) |
| `--tag=worker` | Process title tag (default: `runnable`) |
| `--shmkey=1234` | Read payload from SHM segment instead of stdin |
| `--debug` | Enable full error reporting in the child |
| `--arg-key=value` | Pass `['key' => 'value']` into `Runnable::run()` |
| `--arg-flag` | Pass `['flag' => true]` into `Runnable::run()` |

---

## DEBUG flag — what it changes

`DEBUG=true` in `.env` flips three things:

1. **PHP error visibility.** `error_reporting=E_ALL`, `display_errors=1`, `display_startup_errors=1`. In production these are `0`.
2. **Route cache.** `Router::resolve()` skips reading and writing `Kernel::$pathStorageVolatile/mapping.php`. Every request rescans `pathRoot` for `#[GetMapping]` etc.
3. **Exception responses.** `ExceptionResponseBase` renders rich HTML / JSON pages with full stack trace and metadata. Without DEBUG, only `code` + `message` are returned. See [`../architecture/06-exception.md`](../architecture/06-exception.md).

---

## Boot order

`BaseBoot` runs the hooks in a fixed order — knowing this matters when you cross-reference services:

```
1. configure()          ← Kernel::init() + .env + paths
2. DI Scanner pass      ← discovers #[Singleton] / #[Request] / #[Transient]
3. providers(Container) ← manual DI bindings
4. channels()           ← Kernel::channel('job') etc.
5. httpCors()           ← Cors::configure()
6. health()             ← Health::configure()
7. plugins()            ← Plugin::registry()
8. <entry point body>   ← web() / swoole() / cli() / executor()
```

`BaseBoot::getBootClass()` returns the concrete `Boot` class name set during step 1.

---

## Related

| Topic | File |
|---|---|
| Logging — channels, formats, .env reference | [02-logging.md](02-logging.md) |
| CORS — global + per-route `#[CrossOrigin]` | [03-cors.md](03-cors.md) |
| Health / actuator endpoints | [04-health.md](04-health.md) |
| Plugin registry | [05-plugins.md](05-plugins.md) |
| Routing pipeline & request lifecycle | [../architecture/01-routing.md](../architecture/01-routing.md) |
