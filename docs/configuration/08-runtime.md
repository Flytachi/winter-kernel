# Runtime

The kernel runs the same application code two ways, and the difference that reaches your
code is not the transport — it is **how long a process lives**.

| Runtime | Started by | Process model |
|---|---|---|
| Swoole HTTP server | `php call run` | long-lived workers; routes, singletons and connection pools stay in memory |
| Plain process | `php call <verb>`, `call process` / `call daemon`, the scheduler | one process per invocation, or a long-lived worker without a reactor |

FPM is not served by the kernel. That is a deliberate boundary: a document root,
`public/index.php` and per-request teardown belong to the FPM model, and they are moving
to a separate `winter-fpm` project. The adapters below still exist and are tested, so
that project has a foundation to build on.

---

## Why your code doesn't change

Every internal — `Router`, `ParameterResolver`, middleware, controllers — depends on two
interfaces, never on a concrete transport:

```php
namespace Flytachi\Winter\Kernel\Http\Contracts;

interface HttpRequest  { /* getMethod(), getUri(), getHeader(), getRawBody(), … */ }
interface HttpResponse { /* status(), header(), end(), sendfile() */ }
```

Each transport ships a thin adapter pair:

| Contract | Swoole adapter | FPM adapter |
|---|---|---|
| `HttpRequest` | `SwooleRequest` — wraps `Swoole\Http\Request` | `FpmRequest` — reads `$_SERVER` / `$_GET` / `$_POST` / `php://input` |
| `HttpResponse` | `SwooleResponse` — proxy over `Swoole\Http\Response` | `FpmResponse` — `http_response_code()` / `header()` / `echo` |

`Router::handle()` takes the interfaces:

```php
public function handle(HttpRequest $request, HttpResponse $response): void
```

So the dispatch pipeline, route table, validation and response serialization are
identical whichever transport sits on the boundary. Only the boundary objects differ.

---

## Serving

```bash
php call run                            # bind and serve
php call run dev                        # the same, restarting on file changes
php call run --host=127.0.0.1 --port=9501
```

What happens on `run`:

```
Application::main($argv)
  ├── boot                                  ← Kernel::init, scan, DI, configurers
  ├── Router::fromScan(Kernel::$pathRoot)
  ├── Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)
  ├── new Swoole\Http\Server(host, port)
  ├── $server->set($settings->toArray())    ← from .env + WebConfigurer
  └── on('request', fn($req, $res) => $router->handle(
          new SwooleRequest($req), new SwooleResponse($res)))
```

`SWOOLE_HOOK_ALL` makes PDO, cURL, file I/O and `sleep()` coroutine-aware, so blocking
calls yield instead of stalling the worker. Coroutine isolation keeps per-request state
separate even though the worker is shared.

Server tuning is a `WebConfigurer` the scan finds, not a hook on the application class:

```php
final class WebConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->workers(swoole_cpu_num() * 2)
               ->maxRequest(5000)
               ->maxRequestGrace(500)
               ->set('ssl_cert_file', '/etc/ssl/app.pem');   // any raw Swoole option
    }
}
```

The `.env` shorthands `SERVER_WORKERS`, `SERVER_TASKS`, `SERVER_MAX_REQUEST` and
`SERVER_MAX_REQUEST_GRACE` seed the same settings before the configurer runs.

---

## The one thing to watch: shared state

A Swoole worker is long-lived, so a `#[Singleton]` is created once and **reused across
many requests** in that worker. That is a feature — no per-request rebuild cost — but it
means:

- Do **not** store per-request data (the current user, a request id, a fetched entity)
  on a singleton; it bleeds into the next request.
- Keep singletons stateless, or scope per-request data with `#[Request]` (see
  [`07-di.md`](07-di.md)).
- Avoid module-global mutable statics for request data, for the same reason.

Code that leans on "the process dies after each request" is exactly the code that breaks
here.

### Timers keep a worker alive

Anything that arms a `Swoole\Timer` inside a worker must release it when the worker
exits, or the reactor never drains and Swoole force-kills the worker on shutdown
(`Worker_reactor_try_to_exit(): worker exit timeout`). The kernel releases its own in the
`workerExit` handler; do the same for yours.

---

## Non-HTTP execution

The same application boots for everything else, through the same entry:

| Invocation | Purpose |
|---|---|
| `php call <verb>` | console commands |
| `php call process\|daemon <dot.Class> start [-d]` | managed workers and supervised fleets |
| `php call schedule start [-d]` | the scheduler |

A detached start (`-d`) does not fork the current process: the launcher spawns a fresh
PHP process running `vendor/bin/wKernelRunner`, which boots the application again and
runs the staged payload. See [`../process/03-control.md`](../process/03-control.md).

Without a reactor, coroutine-only machinery degrades on purpose — `Process::spawn()`
forks instead of starting coroutines, and the connection pool falls back to a single
self-maintaining connection.

---

## Source

- `src/WinterApplication.php` — the boot, `serve()`, and the `workerStart` / `workerExit` handlers
- `src/App/Config/ServerSettings.php` — the Swoole options builder
- `src/Http/Contracts/HttpRequest.php`, `HttpResponse.php` — the transport-agnostic contracts
- `src/Http/Adapter/SwooleRequest.php`, `SwooleResponse.php`, `FpmRequest.php`, `FpmResponse.php`
- `src/Route/Router.php` — `resolve()` (cached), `fromScan()` (live), `handle()`
- `src/Route/DevWatcher.php` — the `run dev` file watcher

## See also

- [`01-kernel.md`](01-kernel.md) — paths, `.env`, and the boot order
- [`07-di.md`](07-di.md) — singleton lifetime and the shared-state caveat
- [`../architecture/01-routing.md`](../architecture/01-routing.md) — the dispatch pipeline behind `handle()`
- [`../process/00-overview.md`](../process/00-overview.md) — processes and daemons
