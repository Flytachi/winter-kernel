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

The `.env` shorthands `SERVER_WORKERS`, `SERVER_TASKS`, `SERVER_MAX_REQUEST`,
`SERVER_MAX_REQUEST_GRACE` and `SERVER_MEMORY_LIMIT` seed the same settings before the
configurer runs.

### Memory per worker

```php
$server->workers(4)->memoryLimit('256M');
```

```dotenv
SERVER_MEMORY_LIMIT=256M
```

Say nothing and **the framework does not touch the setting at all** — PHP's own value
stands (128M compiled in, unless a `php.ini` raises it). Configure it and the limit is
applied on worker start.

`memoryLimit()` is a PHP ini, not a Swoole option, so it never reaches
`Swoole\Server::set()`. It lives on `ServerSettings` because it is one half of an
arithmetic whose other half — `worker_num` — is already there:

> The box must hold **`worker_num × memory_limit`**, plus opcache's shared memory.

Four workers at 256M want 1 GiB before a row of data is read. The framework says this
out loud at startup and **warns** when the product exceeds the container's memory
limit (read from cgroup). It warns rather than refuses: every worker peaking together
is a worst case, and over-committing memory is a legitimate choice.

Two properties of this limit are easy to get wrong:

- **It is per process, and a Swoole worker runs many requests at once out of one
  heap.** So it bounds their *sum*, not any single request. Measured on a small API:
  roughly 90 KB of heap per in-flight request — about 1 400 concurrent requests fit in
  128M. When the sum is reached the worker dies and takes every request it was holding
  (measured: 1 193 coroutines discarded at once).
- **Raising it moves the threshold, it does not remove it.** At high enough concurrency
  any ceiling is reached. What keeps a worker alive under a spike is bounding
  concurrency, not the ceiling.

**Do not set `-1`.** Unlimited does not remove the ceiling — it moves it into the
kernel. PHP never stops the process, so the OOM killer eventually sends `SIGKILL`: no
shutdown functions, no log entry, and the whole container when the server is PID 1. A
real limit at least fails through PHP, and the manager restarts that one worker. The
framework warns at startup when it finds `-1`.

### One worker by default

Say nothing and the server runs **a single worker process** — the framework sets no
`worker_num`, and Swoole's own default in `SWOOLE_BASE` mode is one. Concurrency still
works: requests are coroutines inside that process, and a blocking call yields rather
than stalling the others.

What one worker does not give you is more than one CPU core. Measured on a 12-core box,
a single worker saturates one core at roughly 2 400 req/s against a database and 8 500
req/s without one. `->workers(swoole_cpu_num())` is what spreads the load; until then,
extra cores sit idle.

The setting also changes what a pool size means. `maximumPoolSize` is **per worker**, so
one worker makes it the whole server's connection budget, while `workers(12)` multiplies
it by twelve — and a database with `max_connections = 100` will refuse the difference.
Size it as `worker_num × maximumPoolSize` ≤ what the server allows.

---

## Set `opcache.enable_cli=1`

A Swoole server runs under the **CLI SAPI**, and `opcache.enable_cli` is `0` by default.
Leave it off and the server gets no opcache at all — every worker keeps its own copy of
every compiled class instead of sharing one in opcache's shared memory.

Measured on a synthetic 400-class application (560 lines per class), loading all of them:

| | memory held by the process |
|---|---:|
| `opcache.enable_cli=0` | 52.3 MiB |
| `opcache.enable_cli=1` | **7.7 MiB** |

Seven times less, per worker, for one ini line. Time is roughly unchanged — opcache saves
the parse, not the class linking, which every process still does for itself.

```ini
opcache.enable=1
opcache.enable_cli=1          ; required: Swoole is a CLI process
opcache.validate_timestamps=0 ; production — no stat() per file per request
opcache.jit=0                 ; Swoole registers opcode handlers; JIT is auto-disabled anyway
```

The shipped Docker template (`call docker`) already sets these; the values above matter
when you build your own image or run outside a container.

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
