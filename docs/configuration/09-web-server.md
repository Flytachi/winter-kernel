# Web server configuration

Everything the HTTP server is told — where it binds, how many workers it runs, what a
request is allowed to cost — is set in one place: a class implementing `WebConfigurer`.

This page is the reference for that class and for every setting on it. For what the
runtime *is* — worker lifetime, shared state, why your code does not change between
transports — see [`08-runtime.md`](08-runtime.md).

---

## The class

```php
namespace Main\Config;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\App\Config\WebConfigurerAdapter;

final class WebConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->port($args->int('port', 8000))
               ->workers(4)
               ->memoryLimit('256M');
    }
}
```

There is no registration step. The class is found by the boot scan, like every other
`#[Configuration]` or `HealthContributor` — adding configuration is adding a class.

`WebConfigurerAdapter` supplies empty defaults for both methods of the contract, so you
override only the one you need. Implement `WebConfigurer` directly when you want both:

| Method | Concern | When it runs |
|---|---|---|
| `configureServer()` | bind address and server tuning | in the master, **before workers fork** |
| `configureCors()` | the global CORS policy | per request — see [`03-cors.md`](03-cors.md) |

`configureServer()` receives the parsed CLI arguments so *you* decide where the bind
address comes from — a `--port` flag, a flag of your own, `.env`, or a literal. The
handle arrives pre-seeded with the framework default (`--host` / `--port`, falling back
to `0.0.0.0:8000`), so leaving it untouched keeps that.

Several configurers may exist; each is invoked in turn on the same object. The last
write wins, which is worth knowing if two of them touch the same setting.

### Where a value comes from

```
framework defaults  →  .env (SERVER_*)  →  configureServer()  →  Swoole
```

Each stage overrides the one before it, so a value set in code cannot be overridden by
the environment. If you want a setting to stay operator-tunable, read it yourself:

```php
$server->workers((int) env('APP_WORKERS', 1));
```

Only environment variables that are actually set contribute anything — an unset one
leaves the framework default (or Swoole's) in place.

---

## Profiles

Five of the settings below are not numbers you have to choose. They follow from one
question — **how much memory does a single request use?** — and a profile is how you
answer it.

```php
$server->profile(Profile::Performance);
```

| Profile | One request may use | Suits |
|---|---:|---|
| `Stable` | 256 KB | reports, exports, a monolith with wide joins |
| **`Balance`** (default) | 128 KB | ordinary CRUD |
| `Performance` | 64 KB | a thin API, a proxy, an integration bridge |
| `Stress` | — | benchmarks only: every cap and every periodic task off |

**The axis is the shape of a request, not caution.** `Performance` is not "faster but
riskier" — it is for services whose requests are *small*, and it gives such a service
**more** concurrency than `Stable` would, not less. The question is measurable:

```php
$before = memory_get_usage();
// ... your handler ...
$after = memory_get_usage();     // under 64 KB → Performance; over 200 KB → Stable
```

### What a profile decides

Everything else follows arithmetically, from measurements rather than preference:

| Setting | Derivation |
|---|---|
| `maxConcurrency` | available heap ÷ (78 KB + the profile's number + 68 KB) |
| `maxConnections` | twice that — the working ones and one idle client apiece |
| `memoryTrimThreshold` | `memory_limit` ÷ 16 · 8 · 4 |
| `maxRequest` | `memory_limit` × 5% · 10% · 20% ÷ 170 B leaked per request |
| `maxRequestGrace` | a tenth of `maxRequest` |

The three constants are measured, not assumed: **78 KB** is what an in-flight request
holds before allocating anything of its own (600 requests suspended in a trivial handler
cost 78.2 KB each), **68 KB** is an idle connection (measured linearly at 401, 801 and
1 201), and **170 B** is what Swoole leaks per request.

"Available heap" is the limit less what the application already holds at boot — and that
baseline is **measured**, not guessed, so an application with a hundred routes and three
connection pools gets a correspondingly smaller ceiling without being asked.

At 256M, with a booted application carrying ~16M:

| Profile | In flight | Connections | Trim at | Recycle at |
|---|---:|---:|---:|---:|
| Stable | 611 | 1 222 | 16M | 78 951 |
| Balance | 896 | 1 792 | 32M | 157 903 |
| Performance | 1 170 | 2 340 | 64M | 315 806 |

Double the memory limit and every number roughly doubles. The profile stays the same
decision, so it does not have to be revisited when the container is resized.

### Overriding

A profile supplies **defaults**, resolved when each value is read. Anything set
explicitly wins, whatever order the calls are made in:

```php
$server->profile(Profile::Performance)
       ->maxConnections(10_000)      // browsers hold connections open between requests
       ->requestTimeout(120);        // never part of a profile — see below
```

An operator can switch without touching code:

```dotenv
SERVER_PROFILE=stress
```

### `Stress`

Not "Performance, more so". Throughput plateaus long before the memory ceiling — measured
on a live application, 2 250 req/s at 500 concurrent, with more concurrency adding nothing
— so removing the caps does not raise it. What it removes is **periodic interference that
distorts a measurement**: handing memory back pauses for tens of milliseconds and shows in
p99, replacing a worker empties its connection pool mid-run, and the request watchdog runs
a timer of its own.

A run under it can exhaust the worker's memory, and over a long one Swoole's per-request
leak accumulates with no replacement to clear it. Both are fine for a bounded benchmark
and for nothing else. The banner says so on startup.

### What a profile does not decide

`requestTimeout()`, `maxRequestSize()`, `staticPath()`, `workers()` and the bind address.
Those are properties of the application or the deployment, not of how memory is spent — a
report endpoint needs its ten minutes under every profile.

`workers()` in particular is left alone because there is nothing to derive it from:
`swoole_cpu_num()` reports the **host's** cores, not the container's. Measured — under
`--cpus=1` it still answers 12 on a 12-core machine, so deriving from it would start twelve
workers on one core.

---

## Reference

Everything below is a method on `ServerSettings`. Defaults are what you get when nothing
is said.

| Method | Controls | Default | Environment |
|---|---|---|---|
| `profile()` | the memory stance, and the five settings below it | `Balance` | `SERVER_PROFILE` |
| `host()` | bind address | `0.0.0.0` | `--host` |
| `port()` | bind port | `8000` | `--port` |
| `workers()` | worker processes | `1` | `SERVER_WORKERS` |
| `taskWorkers()` | task worker processes | none | `SERVER_TASKS` |
| `maxRequest()` | requests before a worker is replaced | from profile | `SERVER_MAX_REQUEST` |
| `maxRequestGrace()` | random spread on that number | from profile | `SERVER_MAX_REQUEST_GRACE` |
| `maxRequestSize()` | largest accepted request | `8 MB` | `SERVER_MAX_REQUEST_SIZE` |
| `maxConcurrency()` | requests in flight per worker | from profile | `SERVER_MAX_CONCURRENCY` |
| `requestTimeout()` | seconds a request may run | `30` | `SERVER_REQUEST_TIMEOUT` |
| `maxConnections()` | simultaneous TCP connections | from profile | `SERVER_MAX_CONNECTIONS` |
| `idleConnectionTimeout()` | seconds before a quiet connection is closed | off | `SERVER_IDLE_TIMEOUT` |
| `memoryLimit()` | PHP heap per worker | PHP's own (128M) | `SERVER_MEMORY_LIMIT` |
| `memoryTrimThreshold()` | idle memory before it is returned to the OS | from profile | `SERVER_MEMORY_TRIM` |
| `staticPath()` | directory served as static files | none | — |
| `set()` | any raw Swoole option | — | — |

---

### Bind address

```php
$server->host('127.0.0.1')->port(8080);
```

`host()` and `port()` decide where the server listens. Binding to `127.0.0.1` makes the
server unreachable from outside the machine, which is what you want when a reverse proxy
sits in front of it on the same host; `0.0.0.0` accepts from anywhere, which is what a
container needs.

Read them back with `getHost()` and `getPort()` — the banner does.

---

### Processes

```php
$server->workers(4)->taskWorkers(2);
```

**`workers()`** — how many worker processes serve requests. One by default, and nothing
derives it, deliberately: a worker handles many requests at once through coroutines, so one worker is
already concurrent, and the first thing extra workers cost is memory (each has its own
heap, its own connection pool, its own singletons). Add them when a profile says the
worker is CPU-bound, not on principle.

Scaling is close to linear when the bottleneck is in PHP — measured on an endpoint with
no database, 8 551 → 16 265 rps going from one worker to two. It is not linear when the
bottleneck is elsewhere: the same jump on a database-backed endpoint gave 2 049 → 2 331
rps, because PostgreSQL was already using eleven of the machine's twelve cores.

**`taskWorkers()`** — processes for Swoole's task queue. Unset by default. The kernel
does not use them; they are here for applications that call `$server->task()` directly.

---

### Worker replacement

```php
$server->maxRequest(100_000)->maxRequestGrace(10_000);
```

**`maxRequest()`** — requests a worker serves before it is replaced by a fresh one. Not
a precaution but a necessity: Swoole leaks **56 bytes every time a coroutine suspends and
resumes**, measured to the byte across five runs of ten thousand, and surviving both
`gc_collect_cycles()` and `gc_mem_caches()`. An HTTP request always suspends — on the
database, on an upstream call, on writing the response — so every request leaves at least
that behind, and through the full pipeline it measures nearer 170 bytes. At 2 000 req/s
that is roughly 385 MB an hour.

100 000 keeps the leak near 17 MB while making replacement rare enough to be free: the
new worker starts with an empty connection pool and has to fill it, about 30 ms, which
spread over 100 000 requests is 0.0003 ms each.

**`maxRequestGrace()`** — Swoole **adds** a random amount up to this to `max_request`, so
the real limit is `max_request + rand(0, grace)`. Verified: at `max_request = 20,
grace = 15` worker instances served 30, 27, 34 and 26 requests, while at `grace = 0`
every one served exactly 20.

Without it, workers counting to the same number under even traffic recycle almost
together — several connection pools go cold at once and the survivors take the load.

Replacement also closes that worker's connections, which is worth knowing: it is what
reclaims connections abandoned by clients that vanished without closing.

---

### Request size

```php
$server->maxRequestSize(32 * 1024 * 1024);
```

The largest request accepted, **headers included**. Swoole's own limit is 2 MB — measured,
2 000 KB passes and 2 048 KB comes back `413` — which is tight for anything accepting a
document or an image, and invisible when it bites: the client gets a bare status with
nothing explaining it. The default of 8 MB matches PHP's `post_max_size`.

The limit covers the whole packet, not the body alone: at the default a body of
8 388 400 bytes passes and 8 388 608 does not, the difference being the request's own
headers. Leave room for them when sizing an upload endpoint.

Raise it knowing the cost: the request sits in the worker's heap for its whole life, and
that heap is shared by every request in flight. It does **not** cost anything per
connection — measured, per-connection memory is identical at 2 MB, 8 MB and 32 MB.

---

### Concurrency

```php
$server->maxConcurrency(10_000);
```

How many requests one worker processes at the same time. This is the setting that
protects the worker: `memoryLimit()` decides when it dies, this decides whether it gets
there.

Swoole **queues** what exceeds the cap rather than refusing it — measured, twenty
concurrent 0.3-second requests against a cap of 2 all succeeded, taking 3.3 seconds in
total instead of 0.3. Overload becomes latency, not errors.

The default comes from the profile — see [Profiles](#profiles) above.
`getMaxConcurrency()` returns what will be applied.

Whether raising it (or the memory limit behind it) buys anything depends on whether it was
what bound. `worker_concurrency` in `Swoole\Server::stats()` answers that: sitting at the
ceiling means requests are queueing, well below it means the bottleneck is elsewhere.

Time spent queueing counts against the request deadline: a request's coroutine is not
created until the worker lets it through, so without that a client that waited three
seconds would then be granted a fresh thirty.

**It is not a rate limit.** It does not know who is calling, so it cannot give one partner
50 requests a second and another 20, and it delays rather than rejects where a quota has
to answer `429`.

---

### Request timeout

```php
$server->requestTimeout(60);   // globally
$server->requestTimeout(0);    // no deadline at all
```

Seconds a request may run before the server stops waiting for it. Thirty by default,
rather than PHP-FPM's sixty, because under Swoole a stuck request holds more than itself:
a pooled connection is borrowed for the whole request, and the pool is shared by every
request in the worker.

Swoole has no request timeout of its own — verified against the extension, and
`max_request_execution_time` is rejected as an unsupported option. The deadline is
enforced by the framework's own watchdog, which cancels the request's coroutine: `finally`
and `defer` run, so transactions close and pooled connections return, and the client
receives `504`.

Individual routes override it with `#[Timeout]`:

```php
#[RequestMapping('reports')]
#[Timeout(600)]                      // ten minutes for everything in this controller
final class ReportController extends Controller
{
    #[GetMapping('quick')]
    #[Timeout(5)]                    // …except this one
    public function quick(): array { /* … */ }
}
```

It interrupts a request that **waits**. A request burning CPU is not interrupted, because
the event loop is single-threaded — nothing else in the worker runs while it holds the
CPU, the watchdog included.

---

### Connections

```php
$server->maxConnections(2000)->idleConnectionTimeout(600);
```

**`maxConnections()`** — the largest number of simultaneous TCP connections, itself
clamped by the process's file-descriptor limit. Left at Swoole's 100 000.

Connections are not requests, though it is easy to read them that way: a keep-alive
connection serves many requests one after another, and an idle one serves none. Measured
— `max_connection = 2` did not slow six concurrent requests at all, while
`worker_max_concurrency = 2` doubled their total time by queueing them.

They are not free, however. A held keep-alive connection costs about **68 KB of PHP heap**
— measured linearly at 401, 801 and 1 201 connections — so it counts against
`memoryLimit()`, and a worker with the default 128M dies at roughly 1 900 held
connections. The memory returns when they close, and connections spread across workers
(two workers holding 1 200 between them used ~43 MB each rather than 84 MB on one).

**`idleConnectionTimeout()`** — closes a connection that has gone quiet. Off by default,
and understand it before turning it on: Swoole measures the time since the client last
*sent* something, and a client waiting for a slow response sends nothing, so **a request
still being worked on looks exactly like an abandoned connection**. Measured — with the
timeout at 2 seconds a 5-second request was cut at 2.07 s and the client got no response;
at 8 seconds the same request returned normally at 5.00 s. Neither wrote anything to the
log.

So it is a ceiling on request duration as much as on idleness, and it would silently
overrule `#[Timeout]`. Set it above the longest request the application permits. What it
buys is reclaiming connections from clients that vanished without closing — and worker
replacement already does that, which is why it is not on by default.

(`heartbeat_check_interval` is not required for it to work, a common claim and verified
false here.)

---

### Memory

```php
$server->memoryLimit('256M')->memoryTrimThreshold('64M');
```

**`memoryLimit()`** — the PHP heap ceiling for each worker. Untouched by default, so PHP's
own value stands (128M compiled in, unless a php.ini raises it).

It is **per worker and shared by every coroutine in it** — a Swoole worker serves many
requests out of one heap, so this bounds their sum, not any single request. Raising it
moves the threshold; it does not remove it. What stops a worker dying is bounding
concurrency.

At boot the framework says the arithmetic out loud — `worker_num × memoryLimit` plus
opcache's shared memory against the container's limit — and warns when the fleet will not
fit. It warns rather than refuses: every worker peaking at once is rare, and
over-committing is a legitimate choice.

`-1` is accepted but warned about: without a limit PHP never stops, so a runaway request
grows until the kernel's OOM killer sends `SIGKILL` — no shutdown functions, no log line,
and the whole container when the server is PID 1. A real limit at least fails through PHP,
which the manager recovers from.

**`memoryTrimThreshold()`** — how much idle memory a worker may hold before handing it
back to the operating system, checked after each request. `0` never hands anything back.

PHP releases memory to its own allocator, not to the kernel: many small objects live in
chunks that are kept for reuse, so a worker that once built a large result goes on holding
that memory for the rest of its life. Measured — one request that built 600 000 objects
left 258 MB reserved and unused.

The threshold is compared against the **reserve** (what the allocator took from the kernel
minus what is in use), so a busy worker is never trimmed and one trim is enough. It costs
about 80 ms when there is a lot to return and 5 µs when there is nothing, which is what an
ordinary request pays.

---

### Static files

```php
$server->staticPath('resources/static');   // resources/static/app.css → /app.css
```

Serves files from a directory using Swoole's own handler, which answers in C before PHP is
involved: it streams the file rather than reading it into the worker, honours `Range`, and
cannot be walked out of the directory with `..`.

Opt-in — say nothing and no file is ever served, which is what an API-only service wants.
Relative paths resolve against the project root, and a missing directory throws
`ApplicationConfigException` rather than becoming silent 404s at runtime.

Three consequences:

- **The directory is the URL root.** Swoole appends the whole request path to it, so the
  layout on disk mirrors the layout in URLs. Point it at a directory holding assets and
  nothing else — everything under it becomes downloadable.
- **Static responses never reach PHP**, so middleware, CORS and request logging do not
  apply to them.
- **One directory only.** `document_root` is a single value; a plugin's assets have to be
  collected into the one root rather than mounted from a second.

---

### Raw options

```php
$server->set('ssl_cert_file', '/etc/ssl/app.pem')
       ->set('static_handler_locations', ['/assets']);
```

`set()` writes any Swoole option directly, for the ones the framework does not wrap. It is
the escape hatch, not a fallback — a wrapped setting should be set through its method, so
the reasoning above stays attached to it.

An option Swoole does not recognise is **not** an error that stops the server: it prints
`unsupported option` as a warning and carries on. Watch for that line when a setting from
a blog post appears to do nothing — `keepalive_timeout` and `request_timeout`, for
instance, do not exist in Swoole 6.2.

---

## Reading the result

| | |
|---|---|
| `getHost()`, `getPort()` | the bind address |
| `getProfile()` | the profile in force, configured or default |
| `getMaxConcurrency()` | the ceiling that will apply, derived or configured |
| `getMaxConnections()` | connections allowed at once |
| `getMaxRequest()`, `getMaxRequestGrace()` | when a worker is replaced; `0` = never |
| `getMemoryLimit()` | the configured limit, or `null` when the ini is left alone |
| `getRequestTimeout()` | the deadline in seconds; `0.0` when disabled |
| `getMemoryTrimThreshold()` | the threshold, as written |
| `toArray()` | the Swoole options only |

`toArray()` is what reaches `Swoole\Http\Server::set()`. Three of the settings above are
deliberately absent from it — `memoryLimit`, `requestTimeout` and `memoryTrimThreshold`
are a PHP ini value and two framework mechanisms, and Swoole would answer each with
`unsupported option` on every start.

---

## Source

- `src/App/Config/WebConfigurer.php`, `WebConfigurerAdapter.php` — the contract
- `src/App/Config/ServerSettings.php` — every setting on this page
- `src/App/Config/Profile.php` — the four profiles and the arithmetic behind them
- `src/App/Config/WorkerMemory.php` — the boot check and the idle trim
- `src/Route/RequestWatchdog.php` — the request deadline
- `src/WinterApplication.php` — `serve()`, and the `workerStart` / `workerExit` handlers

## See also

- [`08-runtime.md`](08-runtime.md) — what the runtime is, and the caveats that come with a long-lived worker
- [`03-cors.md`](03-cors.md) — the other half of `WebConfigurer`
- [`01-kernel.md`](01-kernel.md) — paths, `.env`, and the boot order
