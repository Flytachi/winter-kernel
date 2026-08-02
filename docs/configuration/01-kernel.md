# Kernel & Bootstrap

The kernel resolves application paths, loads `.env`, and configures logging and the thread runner. It runs once, at the very start of the boot, before anything is scanned.

This page describes the configuration knobs at the bottom of the stack. Logging, CORS, health, and plugins are documented separately — links at the end.

---

## When it runs

`Kernel::init()` is the first thing the boot does, because it decides where the scan
will look. Everything after it — DI, configurers, routes — depends on the paths it sets.

You normally never call it: `WinterApplication::configure()` does, deriving the project
root from the application class's own file.

```php
// bootstrap.php — no Kernel::init() in sight
#[EnableWeb]
final class Application extends WinterApplication
{
    public static function main(array $argv): never { parent::run($argv); }
}
```

Override `configure()` only for a non-standard layout — for instance to keep runtime
files outside the project:

```php
use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\Kernel;

#[EnableWeb]
final class Application extends WinterApplication
{
    protected static function configure(ApplicationArguments $args): void
    {
        Kernel::init(pathRoot: __DIR__, pathStorage: '/var/lib/myapp');
    }

    public static function main(array $argv): never { parent::run($argv); }
}
```

Because `configure()` decides where the scan looks, it cannot itself be a discovered
class — it is the one thing that stays a method on the application.

---

## `Kernel::init()` parameters

```php
Kernel::init(
    pathRoot:            __DIR__,           // project root
    pathEnv:             __DIR__ . '/.env',
    pathResource:        __DIR__ . '/resources',
    pathStorage:         __DIR__ . '/storage',
    pathStorageLog:      __DIR__ . '/storage/logs',
    pathStorageCache:    __DIR__ . '/storage/cache',
    pathStorageRunnable: __DIR__ . '/storage/runnable',
    isTmpVolatile:       false,             // see "Volatile storage" below
);
```

Every parameter is **optional**. When `pathRoot` is omitted, it is derived from the calling location. All other paths are derived from `pathRoot` if not given.

There is no `pathPublic`: a document root is an FPM concept, and the Swoole server decides for itself what it serves (see [`../starter/00-quickstart.md`](../starter/00-quickstart.md)).

| Param | Default |
|---|---|
| `pathEnv` | `$pathRoot . '/.env'` |
| `pathResource` | `$pathRoot . '/resources'` |
| `pathStorage` | `$pathRoot . '/storage'` |
| `pathStorageLog` | `$pathStorage . '/logs'` |
| `pathStorageCache` | `$pathStorage . '/cache'` |
| `pathStorageRunnable` | `$pathStorage . '/runnable'` |

After `init()`, all of these are available as public static properties on `Kernel`:

```php
Kernel::$pathRoot
Kernel::$pathEnv
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

`Kernel::init()` passes `isTmpVolatile: false` by default; `KernelConfig::init()` defaults to `true` (the `Kernel` wrapper flips it). Pass it explicitly if you want the other behaviour.

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

Detaching a process spawns a fresh PHP process running the **thread runner**, which
boots the application again and runs the staged payload. `Kernel::init()` resolves it:

1. `WINTER_THREAD_RUNNER` env var — an absolute path, used when the file exists;
2. otherwise `<pathRoot>/vendor/bin/wKernelRunner`, the binary this package ships.

The runner resolves the project root from its own location, so it must be **copied**
into place rather than symlinked — PHP resolves `__DIR__` through symlinks and the path
would point at the package instead of the project.

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
use Flytachi\Winter\Kernel\Http\Middleware\ClientTimezoneMiddleware;

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
| `getBaseUrl(): string` | `scheme://host[:port]`, standard ports omitted; an https URL never carries `:80` |

Resolution order (each method short-circuits on the first hit):

1. `Forwarded` header (RFC 7239): `proto=`, `host=`, plus port part of `host=`.
2. `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port`.
3. Direct request data — `Host` header, `$_SERVER['HTTPS']` / `REQUEST_SCHEME` / `SERVER_PORT` (FPM), `$request->server['server_port']` (Swoole).
4. Safe defaults — `http`, `localhost`, scheme-derived port.

**Trust policy.** Proxy headers are honoured unconditionally — they take precedence over direct values. If the application is not behind a reverse proxy, strip these headers at the edge (nginx / cloud LB) before they reach PHP. Otherwise a client can spoof `X-Forwarded-Host` and force the backend to return URLs pointing at an attacker domain.

**Swoole SSL.** The Swoole HTTP server does not expose an `https`/`scheme` flag on the request object. Direct-SSL Swoole deployments must terminate TLS at a fronting proxy that sets `X-Forwarded-Proto: https`, otherwise `getScheme()` returns `'http'`.

**Contradictory `https:80`.** Behind a TLS-terminating proxy that forwards the scheme but not the port (or with a misconfigured `HTTPS` flag), the backend server port is often `80` while the scheme is `https`. Since TLS on port 80 is unreachable, `getPort()` drops that contradiction to the https default `443`, so `getBaseUrl()` returns `https://example.com`, never `https://example.com:80`. The mirror case is intentionally **not** collapsed: plain HTTP on `443` is reachable, so `http://example.com:443` is kept explicit. Non-standard ports (`:8443`, `:8080`) are always preserved.

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
use Flytachi\Winter\Kernel\Http\Header;

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

There is one: `Application::main($argv)`, reached from `call`. What happens next depends
on the verb, not on a different entry file.

```php
#!/usr/bin/env php
<?php
chdir(__DIR__);
require './bootstrap.php';
Application::main($argv);
```

| Invocation | What runs |
|---|---|
| `php call run` | boots, then serves the components in the manifest (`serve()`) |
| `php call run dev` | the same with the file watcher |
| `php call <verb>` | boots, then hands the verb to the console |
| `php call` | boots, prints the command list |

Two more entries exist but are not called by hand:

- **`WinterApplication::executor($argv)`** — the child side of `Process::dispatch()`.
  Detaching cannot be a fork (the parent may be a Swoole worker whose reactor must not
  be duplicated), so the launcher spawns a fresh PHP process running
  `vendor/bin/wKernelRunner`, which boots the application again and runs the staged
  payload. Its CLI flags are the thread runner's own:

  | Flag | Purpose |
  |---|---|
  | `--namespace=App` | process title namespace prefix |
  | `--name=MyJob` | process title name (default: class short name) |
  | `--tag=worker` | process title tag |
  | `--shmkey=1234` | read the payload from a SHM segment instead of stdin |
  | `--detach` | daemonise (fork + `setsid`) before running |
  | `--debug` | full error reporting in the child |

- **`WinterApplication::discoverAppClass()`** — how that runner finds your application
  class after requiring `bootstrap.php`, without knowing its name.

FPM has no entry here; it is moving to a separate `winter-fpm` project.

---

## DEBUG flag — what it changes

`DEBUG=true` in `.env` flips three things:

1. **PHP error visibility.** `error_reporting=E_ALL`, `display_errors=1`, `display_startup_errors=1`. In production these are `0`.
2. **Route cache.** `Router::resolve()` skips reading and writing `Kernel::$pathStorageVolatile/mapping.php`. Every request rescans `pathRoot` for `#[GetMapping]` etc.
3. **Exception responses.** `ExceptionResponseBase` renders rich HTML / JSON pages with full stack trace and metadata. Without DEBUG, only `code` + `message` are returned. See [`../architecture/06-exception.md`](../architecture/06-exception.md).

---

## Boot order

The boot runs in a fixed order — knowing it matters when you cross-reference services:

```
1. configure()             ← Kernel::init(): paths, .env, logging
2. Container::init()       ← the shared container
3. one Scanner pass        ← DI classes, #[Configuration]/#[Bean],
                             WebConfigurer, LoggingConfigurer, HealthContributor
4. apply logging           ← discovered LoggingConfigurer classes
5. apply CORS              ← discovered WebConfigurer classes
6. apply actuator          ← #[EnableActuator] + discovered contributors
7. apply imports           ← #[Import] plugin packages
8. dispatch                ← serve() or the console
```

Everything from step 3 onward is discovered, not registered: adding a configurer is
adding a class. `WinterApplication::getAppClass()` returns the concrete application
class name, set at the start of step 1.

---

## Related

| Topic | File |
|---|---|
| Logging — channels, formats, .env reference | [02-logging.md](02-logging.md) |
| CORS — global + per-route `#[CrossOrigin]` | [03-cors.md](03-cors.md) |
| Health / actuator endpoints | [04-health.md](04-health.md) |
| Plugin registry | [05-plugins.md](05-plugins.md) |
| Routing pipeline & request lifecycle | [../architecture/01-routing.md](../architecture/01-routing.md) |
