# Pool — Connection Pool

`PpaConnectionPool` is the connection manager for both runtimes. Under Swoole it is a
pool of live connections shared by coroutines; on a plain process it is a single
connection kept healthy for the life of the process.

The reason it exists is not reuse but **resilience**. Under FPM a database outage healed
itself for free: the process died and the next one reconnected. A long-lived worker keeps
its connections in memory, and a plain channel-based pool hands the same dead sockets out
forever once the database has gone away and come back. This pool is modelled on HikariCP
and actively keeps its connections usable.

---

## How it works

| Runtime | Behaviour |
|---|---|
| **Swoole** | One pool per config class over a `Coroutine\Channel`. A connection is borrowed on the first `db()` call inside a coroutine, cached for that coroutine, and returned automatically by a `defer` when it ends. |
| **Plain process** (console, `call process`, FPM) | One self-maintaining connection per config class, kept for the life of the process. |

Both apply the same lifecycle rules on every borrow:

- **Idle-gated validation.** A connection idle longer than `aliveBypassWindow` (500 ms)
  is probed before it is handed out; a dead one is retired and replaced. A connection in
  active use skips the probe entirely, so healthy traffic pays nothing.
- **maxLifetime rotation.** A connection older than `maxLifetime` (30 min, jittered) is
  replaced before the server can drop it.
- **connectionTimeout.** A borrow waits at most `poolWaitTimeout` for a free connection,
  then fails fast with `PpaPoolException`.

> A `SELECT 1` on *every* borrow is deliberately **not** what happens — that costs a
> round trip per query and churns healthy connections. Only connections that actually sat
> idle are probed.

---

## Making a config pool-aware

By default a config gets a small pool with the lifecycle rules above. To tune it,
implement `PpaPoolConfigInterface` via `PpaPoolTrait`:

```php
use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\K2\Ppa\Pool\PpaPoolConfigInterface;
use Flytachi\Winter\K2\Ppa\Pool\PpaPoolTrait;

class AppDb extends PgDbConfig implements PpaPoolConfigInterface
{
    use PpaPoolTrait;

    public int   $poolMaxConnections = 10;   // upper bound
    public float $poolWaitTimeout    = 5.0;  // seconds before PpaPoolException

    public function setUp(): void
    {
        $this->host     = env('DB_HOST', 'localhost');
        $this->port     = (int) env('DB_PORT', 5432);
        $this->database = env('DB_NAME', 'app');
        $this->username = env('DB_USER', 'postgres');
        $this->password = env('DB_PASS', '');
    }
}
```

The trait supplies defaults for every knob, so a config declares only what it changes and
never breaks when a new one is added.

| Property | Default | Meaning |
|---|---|---|
| `$poolMaxConnections` | `5` | upper bound on connections per config |
| `$poolWaitTimeout` | `3.0` | seconds to wait for a free connection |
| `$keepaliveTime` | `0` (off) | background probe of connections idle at least this long |
| `$idleTimeout` | `0` (never) | close connections idle at least this long, down to `$minimumIdle` |
| `$minimumIdle` | `0` (lazy) | warm connections to keep open |

The last three drive a background housekeeper and are **Swoole-only** — a plain process
has no timer to run them on. Leave them at zero and no timer is ever armed.

Sizing matters against the server: `worker_num × poolMaxConnections × instances` must
stay under the database's `max_connections`.

---

## Failure handling

A query can fail for two very different reasons, and the pool separates them:

- **The connection died** — SQLSTATE class `08`, PostgreSQL `57P01/02/03`, MySQL driver
  codes 2006/2013/2055. The connection is evicted instead of returned, so the next
  borrow — including the next query in the same request — gets a fresh one.
- **The query was rejected** — a constraint violation (`23xxx`), a syntax error
  (`42xxx`), a deadlock. The server is healthy; the connection is left alone.

PostgreSQL needs care here: PDO does not report a lost connection as `08006`. With the
socket gone there is no result to take a SQLSTATE from, so it arrives as `HY000` with
libpq's generic code `7` — the same code an ordinary syntax error carries. When the
driver's verdict is that inconclusive, the pool probes the connection and decides from
the answer.

**The failed statement is never retried.** The pool cannot know what ran: the break may
have happened after the server applied the write, so a replay could duplicate it, and
replaying one statement of an interrupted transaction is meaningless. One request fails;
the connection is thrown away.

Repositories report failures automatically. Code that uses `db()` directly can do the
same:

```php
try {
    $cdo->query($sql);
} catch (Throwable $e) {
    PpaConnectionPool::reportFailure(AppDb::class, $e);   // evicts only on a real loss
    throw $e;
}
```

---

## Observability

Each worker holds its own pool, so numbers are **per worker** — a saturated worker is a
real stall even when the fleet total looks roomy.

```bash
php call db pool
```

reads what the workers publish and prints the fleet:

```
App\Config\AppDb
  active 12 · idle 3 · total 15 · maximum 20 · workers 2
  saturated  1 of 2 workers                    [SATURATED]
per worker
  worker#0  App\Config\AppDb  active=2  idle=3 total=5  max=10  age=0s
  worker#1  App\Config\AppDb  active=10 idle=0 total=10 max=10  age=3s
```

The CLI is a separate process and cannot read a running server's memory, so each worker
publishes its stats to the shared store on a timer — `PPA_POOL_TELEMETRY` (seconds,
default `5`, `0` disables). Records carry a TTL of three intervals, so a worker that
stops simply expires; a worker holding no pool writes nothing at all.

The same numbers appear in the `db` component of `/actuator/health`, nested under the
datasource they belong to, where a saturated pool marks it `degraded`.

---

## API

### `PpaConnectionPool::db(string $configClass): CDO`

Returns a live connection. Inside a coroutine it borrows one and registers the automatic
return; elsewhere it hands back the process-wide connection. Throws `PpaPoolException`
when no connection can be obtained in time.

### `PpaConnectionPool::getConfigDb(string $configClass): DbConfigInterface`

The initialised config instance (`setUp()` already called), cached per class.

### `PpaConnectionPool::showDbConfigs(): DbConfigInterface[]`

Every registered config — for diagnostics.

### `PpaConnectionPool::stats(): array`

Live utilisation of each pool in **this** process: `total`, `idle`, `active`, `maximum`
keyed by config class.

### `PpaConnectionPool::reportFailure(string $configClass, Throwable $e): bool`

Classifies a failure and evicts the connection when it is genuinely lost. Returns whether
it evicted.

### `PpaConnectionPool::reset()` / `::shutdown()`

Two opposite things, and the difference matters:

- **`reset()`** — for a **forked child**: forget inherited connections **without closing
  them**, since the sockets still belong to the parent. Registered as the fork-safety
  reset, so a daemon worker reconnects on its own.
- **`shutdown()`** — for a process that genuinely owns its connections: close them
  properly and release the housekeeping timers. The kernel calls it on worker exit.

---

## Exceptions

`PpaPoolException` — no connection could be obtained: the pool was exhausted within
`poolWaitTimeout`, or opening one failed.

---

## See also

- [`02-configuration.md`](02-configuration.md) — declaring a database config
- [`../configuration/04-health.md`](../configuration/04-health.md) — the actuator report
- [`../console/06-db.md`](../console/06-db.md) — `call db`
