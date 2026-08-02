# Fixed-size pools

`Executors::common()` never says no. Every `execute()` starts a coroutine at
once, so a burst of a thousand tasks becomes a thousand coroutines fighting over
the same database pool and the same CPU. Usually that is fine — coroutines are
cheap and I/O-bound work interleaves. Sometimes it is not: an external API that
allows five connections, a report that each eats 200 MB, a mailer you must not
hammer.

A **fixed-size pool** puts a ceiling on it: at most *N* tasks run at once, the
rest wait for a slot. It is `Executors.newFixedThreadPool(n)` from Java, adapted
to coroutines.

```php
use Flytachi\Winter\Kernel\Concurrent\Executors;

$pool = Executors::newFixedExecutor(5);   // at most 5 running at a time

for ($i = 0; $i < 100; $i++) {
    $pool->submit(fn() => $gateway->send($messages[$i]));   // 5 in flight, 95 waiting
}
```

The 100 submits return immediately; only five bodies run concurrently, the other
ninety-five park until a slot frees. Nothing is lost, nothing blocks the caller.

---

## Creating one

```php
Executors::newFixedExecutor(
    int $concurrency,                         // max tasks running at once (>= 1)
    int $queue = 0,                           // waiting slots; 0 = unbounded
    RejectPolicy $onReject = RejectPolicy::ABORT,
): BoundedExecutorService
```

- **`concurrency`** — the ceiling. Five means five bodies run at the same time.
- **`queue`** — how many tasks may *wait* for a slot. `0` (the default) is an
  unbounded wait queue: submitting always succeeds, matching Java's
  `newFixedThreadPool`. A positive value caps the backlog and turns on the reject
  policy.
- **`onReject`** — what happens to a task that arrives when the pool is full and
  the queue is at capacity. Only reached when `queue > 0`. See
  [Overflow](#overflow).

`newFixedExecutor(5)` is the common case: cap the parallelism, let everything
else queue.

---

## Giving a service its own pool

A pool is most useful behind [`#[Async]`](03-async.md): register the pool once,
name it on the method, and every call to that method runs on it — bounded,
without a line of executor code at the call site.

**Register it as a singleton** in your Boot's `providers()`:

```php
use Flytachi\Winter\Kernel\Concurrent\Executors;

protected static function providers(Container $c): void
{
    $c->singleton('gatewayPool', fn() => Executors::newFixedExecutor(5));
}
```

**Name it on the method:**

```php
class SmsService
{
    #[Async(executor: 'gatewayPool')]
    public function send(string $to, string $body): void
    {
        $this->gateway->send($to, $body);
    }
}
```

**Call it normally** — the calls funnel through `gatewayPool`, five at a time:

```php
foreach ($recipients as $to) {
    $this->sms->send($to, $body);   // returns immediately, bounded to 5 in flight
}
```

> **It must be a `singleton`.** The cap lives in the pool object's state, so every
> `#[Async('gatewayPool')]` call has to resolve the *same* instance. A `bind()` or
> transient registration hands out a fresh pool each call, and the ceiling never
> holds.

---

## The bound is a Swoole property

A pool caps *concurrency*, and concurrency only exists under Swoole. The backend
switches on the runtime, per call:

| Runtime | What a pool does |
|---|---|
| **Swoole** (coroutine) | Real ceiling: N coroutines run, the rest park on a `Channel` semaphore. |
| **PHP-FPM** | Delegates to the deferred backend: tasks run **sequentially after the response is flushed**. There is no parallelism to bound, so `concurrency` is a no-op. |
| **Plain CLI** | Deferred / sequential likewise. |

This mirrors `Executors::common()` — see [01-executors.md](01-executors.md#backends).
The pool never breaks in a non-Swoole runtime; it degrades to running tasks one
at a time, which is all a synchronous SAPI can do. Design for Swoole; treat the
FPM/CLI behaviour as a correct-but-serial fallback.

---

## Overflow

With the default unbounded queue (`queue = 0`) a pool never rejects — a slow
drain just grows the backlog of parked coroutines, which costs memory. When you
need a hard limit, set `queue` and pick a `RejectPolicy` for the moment both the
slots and the queue are full:

```php
use Flytachi\Winter\Kernel\Concurrent\RejectPolicy;

Executors::newFixedExecutor(
    concurrency: 5,
    queue: 50,
    onReject: RejectPolicy::ABORT,
);
```

| Policy | On overflow | The caller sees |
|---|---|---|
| `ABORT` (default) | Throw `RejectedExecutionException` at submit | An exception — fail fast, shed load loudly |
| `CALLER_RUNS` | Run the task inline, right here | Back-pressure: the submitter does the work and slows down |
| `DISCARD` | Drop the task | A **cancelled** future (`isCancelled()` true); for `execute()`, nothing |

Which one you feel depends on how you called it:

**Fire-and-forget (`void` / `execute`)** — you hold no handle, so the *pool*
reacts: `ABORT` surfaces the exception to whoever triggered the call (a scheduler
tick, a request), `CALLER_RUNS` makes that caller do the work, `DISCARD` drops it
silently.

**With a result (`: Future` / `submit`)** — you hold the handle and decide:

```php
$future = $this->reports->build($month);   // #[Async('reportPool')] : Future
try {
    $report = $future->get(timeout: 30.0);
} catch (RejectedExecutionException $e) {
    // pool saturated → degrade (503, retry later, …)
}
```

Rule of thumb: unbounded queue for work that must not be lost; bounded + `ABORT`
or `DISCARD` for work where "too much at once" should shed rather than pile up;
`CALLER_RUNS` when you want the producer to naturally slow to the pool's pace.

---

## Reading the gauges

`newFixedExecutor` returns a `BoundedExecutorService`, which reports its live
occupancy — the equivalent of `ThreadPoolExecutor.getActiveCount()` /
`getQueue().size()`:

```php
$pool->concurrency();        // the ceiling N
$pool->activeCount();        // running right now (0..N)
$pool->queuedCount();        // accepted, waiting for a slot
$pool->remainingCapacity();  // room before the reject policy applies (PHP_INT_MAX if unbounded)
```

Surface them where the owning process is already observed — a `status` command,
an actuator endpoint: *"gateway: 5/5 busy, 12 queued."* The gauges are live only
under Swoole; in a sequential runtime they stay at rest.

> These are counters, not a task registry. "How many are waiting" is cheap; "which
> tasks are waiting" is not tracked — if you need a named, inspectable, durable
> queue, that is a job-queue built on [Process/Daemon](../process/00-overview.md),
> not this in-memory pool.

---

## Two things to keep in mind

**The pool is per-process.** It lives in the memory of whichever process holds
it. Under a Swoole HTTP server every worker has its own instance, so a pool of 5
registered as a singleton caps at 5 *per worker* — with 4 workers, up to 20 run
across the server. The scheduler process has its own, separate. A single ceiling
shared across processes or hosts is a different tool (an external broker), not an
in-memory pool.

**A pool is not durable.** The queue is RAM. If the process dies, the parked
tasks die with it. That is the trade for being instant and free — no Redis, no
serialization, no network. When you need tasks to survive a restart, reach for a
persistent queue, again on Process/Daemon.

---

## When to use what

| Need | Reach for |
|---|---|
| One-off background work, no limit | `Executors::common()` |
| Cap the parallelism of a workstream | `Executors::newFixedExecutor(n)` |
| A service always async, on its own bounded pool | `#[Async('id')]` + a registered `newFixedExecutor` |
| One global cap across processes / hosts, durable | a job queue on [Process/Daemon](../process/00-overview.md) |

---

## See also

- [01-executors.md](01-executors.md) — `Executors`, the primitive backends
- [03-async.md](03-async.md#choosing-an-executor) — `#[Async(executor: 'id')]`
- [02-future.md](02-future.md) — the handle `submit()` returns
- [`schedule/01-usage.md`](../schedule/01-usage.md#bounding-concurrency-with-a-named-pool) — sharing a pool between the scheduler and the API
