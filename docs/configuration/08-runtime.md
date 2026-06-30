# Runtime — FPM & Swoole

The same application runs under two HTTP runtimes without touching a line of
controller or middleware code:

| Runtime | Entry point | Process model |
|---------|-------------|---------------|
| PHP-FPM / Apache (CGI) | `Boot::web()` | One request per process, no shared state |
| Swoole HTTP server | `Boot::swoole()` | Long-lived workers, routes & state stay in memory |

You pick the runtime by which entry point your bootstrap file calls — the
kernel handles the rest.

---

## Why your code doesn't change

Every K2 internal — `Router`, `ParameterResolver`, middleware, controllers —
depends only on two interfaces, never on a concrete transport:

```php
namespace Flytachi\Winter\K2\Http\Contracts;

interface HttpRequest  { /* getMethod(), getUri(), getHeader(), getRawBody(), … */ }
interface HttpResponse { /* status(), header(), end(), sendfile() */ }
```

Each runtime ships a thin adapter pair:

| Contract | FPM adapter | Swoole adapter |
|----------|-------------|----------------|
| `HttpRequest` | `FpmRequest` — reads `$_SERVER` / `$_GET` / `$_POST` / `php://input` | `SwooleRequest` — wraps `Swoole\Http\Request` |
| `HttpResponse` | `FpmResponse` — `http_response_code()` / `header()` / `echo` | `SwooleResponse` — thin proxy over `Swoole\Http\Response` |

`Router::handle()` takes the interfaces:

```php
public function handle(HttpRequest $request, HttpResponse $response): void
```

So the dispatch pipeline, route table, validation, and response serialization
are identical in both modes. Only the boundary objects differ.

---

## FPM — `Boot::web()`

```php
// public/index.php
require __DIR__ . '/../bootstrap.php';
Boot::web();
```

One request lives in one process and then dies — no state survives between
requests. The pipeline:

```
Boot::web()
  ├── boot()                       ← configure + DI scan (once per request)
  ├── Router::resolve(pathRoot)    ← cached route table when DEBUG=false
  ├── $router->static(pathPublic)  ← serve existing public files directly
  └── $router->handle(new FpmRequest(), new FpmResponse())
```

**Route cache.** `Router::resolve()` avoids re-scanning on every request:

| `DEBUG` | Behavior |
|---------|----------|
| `false` | Loads `storage/volatile/mapping.php` if present; otherwise scans **once** and writes the cache for subsequent requests (first boot after deploy). |
| `true`  | Always rescans; cache is never read or written (dev mode). |

If the cache write fails, the request still serves — the kernel logs a
warning and runs uncached.

**Static files.** `static(pathPublic)` short-circuits GET requests whose URI
maps to an existing file in `Kernel::$pathPublic`, skipping route dispatch.
Behind nginx this is usually a no-op because nginx serves the file first.

---

## Swoole — `Boot::swoole()`

```php
// server.php
require __DIR__ . '/bootstrap.php';
Boot::swoole();                       // defaults: 0.0.0.0:9501
// or: Boot::swoole('0.0.0.0', 8080);
```

Requires `ext-swoole`. The route scan runs **once at startup** and stays in
memory for the whole server lifetime — every request reuses the same
`Router` instance:

```
Boot::swoole(host, port)
  ├── boot()                              ← configure + DI scan (once, at startup)
  ├── Router::fromScan(pathRoot)          ← scan once, keep routes in memory
  ├── $router->static(pathPublic)
  ├── Runtime::enableCoroutine(SWOOLE_HOOK_ALL)   ← all blocking I/O made coroutine-friendly
  ├── Runtime::boot(RuntimeMode::Swoole)
  ├── new Swoole\Http\Server(host, port)
  ├── $server->set(static::swooleConfig())
  ├── MemoryWatcher attached              ← per-worker memory baseline reporting
  └── on('request', fn($req, $res) => $router->handle(
          new SwooleRequest($req), new SwooleResponse($res)))
```

`SWOOLE_HOOK_ALL` makes PDO, cURL, file, and `sleep()` coroutine-aware, so
blocking I/O yields instead of stalling the worker. Coroutine isolation keeps
per-request state separate even though workers are shared.

**Server tuning** — override `swooleConfig()` in your `Boot` class; the array
is passed straight to `Swoole\Http\Server::set()`:

```php
protected static function swooleConfig(): array
{
    return [
        'worker_num'   => swoole_cpu_num() * 2,
        'max_request'  => 5000,
        'enable_coroutine' => true,
    ];
}
```

Return `[]` for Swoole's built-in defaults. A `max_request` ceiling is a
cheap safety net — workers recycle after N requests, bounding any slow leak.

**MemoryWatcher** records each worker's memory baseline at `workerStart` and
reports per-request growth — useful for spotting leaks introduced by shared
state.

---

## The one thing to watch: shared state

This is the only behavioral difference that reaches your code.

Under **FPM**, every request starts from a clean process, so a `#[Singleton]`
effectively lives for one request. Under **Swoole**, a worker is long-lived —
a `#[Singleton]` is created once and **reused across many requests** in that
worker. That is a feature (no per-request rebuild cost), but it means:

- Do **not** store per-request data (the current user, a request ID, a
  fetched entity) on a singleton — it will bleed into the next request.
- Keep singletons stateless, or scope per-request data with `#[Request]`
  (see the `flytachi/winter-di` docs and [`07-di.md`](07-di.md)).
- Avoid module-global mutable statics for request data — same reason.

Code written this way runs identically under both runtimes. Code that leans
on "the process dies after each request" works under FPM and breaks under
Swoole.

---

## Non-HTTP entry points

The same `Boot` also drives the CLI and the thread executor — these are not
HTTP runtimes but share `boot()`:

| Entry point | Used by | Purpose |
|-------------|---------|---------|
| `Boot::cli($argv)` | `call` binary | Console commands |
| `Boot::executor($argv)` | `wKernelExecutor` | Runs a `Runnable` in a forked child (threads/jobs) |

See [`../console/00-overview.md`](../console/00-overview.md) and
[`../threads/00-overview.md`](../threads/00-overview.md).

---

## Choosing a runtime

| Prefer… | When |
|---------|------|
| FPM | Standard hosting, nginx/Apache in front, no persistent connections, simplest ops. |
| Swoole | High throughput / low latency, WebSockets, persistent pools, in-memory caches — and your code is stateless across requests. |

Both read the same `.env`, the same `Boot` hooks, and the same route table.
Switching is changing which entry point the runtime file calls.

---

## Source

- `src/BaseBoot.php` — `web()`, `swoole()`, `swooleConfig()` entry points
- `src/Http/Contracts/HttpRequest.php`, `HttpResponse.php` — the transport-agnostic contracts
- `src/Http/Adapter/FpmRequest.php`, `FpmResponse.php`, `SwooleRequest.php`, `SwooleResponse.php`
- `src/Route/Router.php` — `resolve()` (FPM, cached), `fromScan()` (Swoole, in-memory), `handle()`
- `src/Route/MemoryWatcher.php` — Swoole per-worker memory reporting

## See also

- [`01-kernel.md`](01-kernel.md) — the `Boot` class and entry points
- [`07-di.md`](07-di.md) — singleton lifetime and the shared-state caveat
- [`../architecture/01-routing.md`](../architecture/01-routing.md) — the dispatch pipeline behind `handle()`
