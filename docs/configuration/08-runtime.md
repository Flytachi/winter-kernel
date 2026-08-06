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
        $server->workers(4)
               ->maxRequest(5000)
               ->maxRequestGrace(500)
               ->set('ssl_cert_file', '/etc/ssl/app.pem');   // any raw Swoole option
    }
}
```

The `.env` shorthands seed the same settings before the configurer runs:

| Variable | Method | Default |
|---|---|---|
| `SERVER_WORKERS` | `workers()` | 1 — see below |
| `SERVER_TASKS` | `taskWorkers()` | Swoole's |
| `SERVER_MAX_REQUEST` | `maxRequest()` | from the profile |
| `SERVER_MAX_REQUEST_GRACE` | `maxRequestGrace()` | from the profile |
| `SERVER_PROFILE` | `profile()` | `balance` |
| `SERVER_MAX_REQUEST_SIZE` | `maxRequestSize()` | `8388608` (8 MB) |
| `SERVER_MAX_CONCURRENCY` | `maxConcurrency()` | from the profile |
| `SERVER_IDLE_TIMEOUT` | `idleConnectionTimeout()` | off |
| `SERVER_MAX_CONNECTIONS` | `maxConnections()` | from the profile |
| `SERVER_MEMORY_LIMIT` | `memoryLimit()` | untouched — PHP's own 128M |
| `SERVER_MEMORY_TRIM` | `memoryTrimThreshold()` | from the profile |
| `SERVER_REQUEST_TIMEOUT` | `requestTimeout()` | `30` seconds |

### Limits, and the profile behind them

Most of what bounds a request — how many run at once, how many clients may be connected,
how large a request may be, when a worker is replaced — follows from a single profile:

```php
$server->profile(Profile::Performance);
```

| Profile | One request may use | Suits |
|---|---:|---|
| `Stable` | 512 KB | reports, exports, a monolith with wide joins |
| **`Balance`** (default) | 128 KB | ordinary CRUD |
| `Performance` | 64 KB | a thin API, a proxy, an integration bridge |
| `Stress` | — | benchmarks only |

The axis is the **shape of a request, not caution**: `Performance` gives a service with
small requests more concurrency, not less. Everything else is derived from measured
constants — 78 KB per in-flight request, 68 KB per open connection, 180 B leaked by a
request that arms a timer — against the heap left after the application's own baseline,
which is measured at startup rather than assumed. Worker replacement is the one derived
setting that does *not* vary with the profile: what it guards against is the same for all
of them, and what it costs — the requests the worker was serving — is too.

Swoole **queues** what exceeds the concurrency cap rather than refusing it, so overload
becomes latency, not errors — measured, twenty concurrent 0.3-second requests against a
cap of 2 all succeeded, taking 3.3 seconds instead of 0.3. Time spent queueing counts
against the request deadline, since a request's coroutine is not created until the worker
lets it through.

**None of it is a rate limit.** It has no idea who is calling, so it cannot give one
partner 50 requests a second and another 20, and it delays rather than rejects where a
quota has to answer `429`.

Every setting, its measurement, and how to override it:
[`09-web-server.md`](09-web-server.md).

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

### Workers are replaced, and have to be

A worker does not live forever: after a number of requests derived from the profile —
157 903 under `Balance` at 256M — it is replaced by a fresh one. This is a default, not a
precaution.

**Swoole leaks 56 bytes every time a coroutine suspends and resumes.** Measured to the
byte across five runs of ten thousand, and it survives both `gc_collect_cycles()` and
`gc_mem_caches()`. Neither a coroutine on its own nor a timer on its own leaks — only the
pair. An HTTP request always suspends: on the database, on an upstream call, on writing
the response. Through the full pipeline it measures nearer 170 bytes a request.

| Load | Leaked | A 256M worker lasts |
|---:|---:|---:|
| 500 req/s | 96 MB/h | 2.7 hours |
| 2 000 req/s | 385 MB/h | 42 minutes |
| 5 000 req/s | 961 MB/h | 16 minutes |

So the choice is not whether to recycle but how often. 100 000 keeps the leak near 17 MB
— comfortably inside any sane limit — while being rare enough that the cost of
replacement disappears. Replacement is not free: the new worker starts with an empty
connection pool and has to fill it, about 30 ms, which spread over 100 000 requests is
0.0003 ms each.

`SERVER_MAX_REQUEST_GRACE` (default `10000`) is what keeps workers from recycling
together. Swoole **adds** a random amount up to the grace, so the real limit is
`max_request + rand(0, grace)` — verified: at `max_request = 20, grace = 15` worker
instances served 30, 27, 34 and 26 requests, while at `grace = 0` every one served exactly
20. Without it, workers counting to the same number under even traffic go cold almost
together and the survivors take the load.

> **What a replacement resets.** Everything living in that worker's memory: `#[Singleton]`
> instances, in-process caches, its share of the connection pool. This was always possible
> — a worker could die at any moment — but it now happens on a schedule. State that must
> outlive a request belongs in a database or a cache, not in a singleton's property.

### Giving memory back

PHP frees memory to **its own allocator**, not to the kernel. Whether the kernel ever
sees it again depends on how it was taken: one large block is mapped directly and
unmapped on release, but many small objects live in the allocator's chunks, and those
are kept for reuse. So a worker that once built a large result goes on holding that
memory for the rest of its life — measured on a live worker, `top` reading 114 MiB
against 6 MiB actually in use.

On a host running several containers that is first-come-first-served: one spike and the
memory is spoken for.

After each request the framework checks how much the allocator is holding idle and hands
it back when that passes a threshold — **32M by default**:

```php
$server->memoryLimit('256M')->memoryTrimThreshold('64M');
```

```dotenv
SERVER_MEMORY_TRIM=64M
```

`0` never hands anything back.

Measured on 600 000 small objects:

| | in use | taken from the OS | idle reserve |
|---|---:|---:|---:|
| request working | 282 MB | 284 MB | 1.7 MB |
| request finished | 10 MB | 268 MB | **258 MB** |
| after the release | 10 MB | 12 MB | 2 MB |

The decision is made on that **reserve** — what the allocator took minus what is in use —
rather than on the process peak, for two reasons. A worker in the middle of a large
request has a high peak and almost no reserve, so sustained load never trips it; and the
reserve falls again once released, so one release is enough. `memory_get_peak_usage()`
never decreases, and would trigger on every request for the rest of the worker's life.

Cost, measured: **80 ms** when there is 128 MB to give back, **5 µs** when there is
nothing — which is what an ordinary request pays — and about 10 % on the next large
allocation, which has to take fresh chunks. The release runs after the response is sent,
so no client waits for it.

`gc_collect_cycles()` does none of this: measured, it collects nothing here and frees
nothing. What is being held are not cycles.

`WorkerMemory::idleReserve()` returns the same number, if you want to see where a
worker's resident size went.

### Request timeout

A request that never finishes holds more than itself: its pooled database connection is
borrowed for the whole request, and the pool is shared by every request in the worker.
So requests have a deadline — **30 seconds by default**.

```php
$server->requestTimeout(60);   // globally
$server->requestTimeout(0);    // no deadline at all
```

```dotenv
SERVER_REQUEST_TIMEOUT=60
```

Individual routes override it with `#[Timeout]`, on the controller or on the method —
the method wins:

```php
use Flytachi\Winter\Kernel\Route\Annotation\Timeout;

#[RequestMapping('reports')]
#[Timeout(120)]                       // everything here gets two minutes
class ReportController extends Controller
{
    #[GetMapping('export')]
    #[Timeout(600)]                   // …but the export gets ten
    public function export(): ResponseEntity { ... }

    #[GetMapping('stream')]
    #[Timeout(0)]                     // …and this one is never timed out
    public function stream(): ResponseEntity { ... }
}
```

The attribute is read once, during the scan, and stored in the compiled route table —
nothing is reflected per request.

When the deadline passes the request's coroutine is cancelled: `finally` and `defer` run
(so transactions close and pooled connections go back), and the client receives
**504 Gateway Timeout**.

**Swoole has no request timeout of its own.** Verified against the extension: none of its
67 server options concerns execution time, `max_request_execution_time` appears nowhere in
the binary, and setting it changes nothing — a handler sleeping three seconds under a
one-second "limit" still answers 200 after three, in both server modes. What it does
produce is `unsupported option` on every start.

#### What it can and cannot interrupt

| The handler | What happens at the deadline |
|---|---|
| waits on I/O and does not swallow errors | interrupted, `finally`/`defer` run, client gets 504 |
| waits on I/O but catches `Throwable` | cannot complete any further I/O; its result is discarded and the client still gets 504 |
| burns CPU without yielding | **not interrupted** — it runs to completion |

The last row is a property of a single-threaded event loop, not a gap in the
implementation: while a handler loops without touching I/O nothing else in the worker
runs, the watchdog included. Measured — a sweep scheduled for 0.10 s woke at 1.91 s,
behind the 1.8 s loop it was waiting on.

PHP-FPM has the mirror-image limitation: its `max_execution_time` kills a runaway loop
but not a hung query. Neither model covers both.

The second row exists because cancellation is **not sticky**: after application code
catches the `CanceledException` the coroutine is fully functional again — a following
`sleep(2)` really sleeps two seconds. So an overdue request is cancelled on every sweep,
and the framework answers 504 rather than sending a report built from queries that never
ran.

### One worker by default

Say nothing and the server runs **a single worker process** — the framework sets no
`worker_num`, and Swoole's own default in `SWOOLE_BASE` mode is one. Concurrency still
works: requests are coroutines inside that process, and a blocking call yields rather
than stalling the others.

What one worker does not give you is more than one CPU core. Measured on a 12-core box,
a single worker saturates one core at roughly 2 400 req/s against a database and 8 500
req/s without one. `->workers(n)` is what spreads the load; until then, extra
cores sit idle. Set `n` from the cores the **container** was given — `swoole_cpu_num()`
reports the host's, and answers 12 under `--cpus=1` on a 12-core machine.

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

- [`09-web-server.md`](09-web-server.md) — `WebConfig`, and every server setting in one reference
- [`01-kernel.md`](01-kernel.md) — paths, `.env`, and the boot order
- [`07-di.md`](07-di.md) — singleton lifetime and the shared-state caveat
- [`../architecture/01-routing.md`](../architecture/01-routing.md) — the dispatch pipeline behind `handle()`
- [`../process/00-overview.md`](../process/00-overview.md) — processes and daemons
