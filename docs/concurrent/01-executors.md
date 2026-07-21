# Executors

An **executor** is the thing that actually runs a background task. Application
code never says "spawn a coroutine" or "defer until the response is flushed" —
it hands the task to an `ExecutorService` and the executor decides, based on the
runtime it is living in.

That indirection is the whole point: the same three lines of application code
behave correctly under Swoole and under PHP-FPM, and there is no
`if (Runtime::isSwoole())` anywhere in your project.

```php
use Flytachi\Winter\K2\Concurrent\Executors;

Executors::common()->execute(fn() => $mixpanel->track($userId, 'signup'));
```

---

## `Executors`

```php
use Flytachi\Winter\K2\Concurrent\Executors;
```

### `common(): ExecutorService`

Returns the shared executor matching the current runtime — the coroutine
executor inside a Swoole coroutine, the deferred one everywhere else.

The decision is made **on every call**, never cached, because one process can
legitimately be in both states: a Swoole worker booting outside a coroutine and
then serving requests inside one.

This is what application code should use.

### `newCoroutineExecutor(): ExecutorService`

A fresh coroutine-backed executor. Useful when you want an isolated
`shutdown()` / `awaitTermination()` scope — for example a batch job that must
drain its own tasks without waiting for everything else in the worker.

Requires an active coroutine at submit time; otherwise every call throws
`RejectedExecutionException`.

### `newDeferredExecutor(): ExecutorService`

A fresh deferred executor. Chiefly useful in tests, and when you deliberately
want synchronous behaviour inside a Swoole process.

### `shutdownCommon(?float $timeout = null): bool`

Stops the shared executors accepting new work and waits for what is already
running. Returns `false` if the timeout elapsed with tasks still in flight.

Intended for graceful shutdown — worker recycling under Swoole, or an explicit
flush at the end of a console command:

```php
Executors::common()->execute(fn() => $reporter->send($stats));

Executors::shutdownCommon(timeout: 5.0);   // let it finish before we exit
```

---

## `ExecutorService`

The contract every backend implements.

### `submit(callable $task, mixed ...$args): Future`

Runs the task and returns a handle on its result.

```php
$future = Executors::common()->submit($api->fetch(...), $id);
$value  = $future->get();
```

Extra arguments are passed to the task, which pairs nicely with PHP's
first-class callable syntax (`$api->fetch(...)`) — no closure needed.

The task is **guaranteed to run** even if the returned future is never awaited.

### `execute(callable $task, mixed ...$args): void`

Fire-and-forget: no handle, no result.

Because nobody can observe a failure through a future, **a throwable is logged**
instead of being silently dropped. `submit()` does not log — its owner is
expected to see the failure through `get()`.

### `invokeAll(iterable $tasks, ?float $timeout = null): array`

Submits every task and blocks until all of them have settled. Returns the
futures in the order the tasks were given; inspect each one individually.

```php
$results = Executors::common()->invokeAll([
    fn() => $billing->fetch($id),
    fn() => $crm->fetch($id),
    fn() => $support->fetch($id),
]);

foreach ($results as $future) {
    if ($future->isCompletedExceptionally()) {
        $logger->warning($future->failure()->getMessage());
        continue;
    }
    $data[] = $future->get();
}
```

Under Swoole the three calls run **concurrently**: total time is the slowest
one, not the sum. Under FPM they run in sequence — the results are identical,
the wall clock is not.

`$timeout` bounds the whole batch. A task that outlives it keeps running; only
the waiting stops.

### `shutdown(): void` / `isShutdown(): bool`

Stops accepting new tasks. Already submitted ones still run to completion.
Submitting afterwards throws `RejectedExecutionException`.

### `awaitTermination(?float $timeout = null): bool`

Blocks until every submitted task has settled. Returns `false` on timeout.

> Under Swoole this only works from **inside a coroutine**. Outside one the
> scheduler is not running, so busy-waiting could never let the pending tasks
> advance; the method reports the current state instead of hanging.

---

## Backends

### `CoroutineExecutorService` — Swoole

Each task becomes a coroutine in the current worker. A task blocked on I/O
yields, and the worker keeps serving other work — this is the only backend that
offers real concurrency.

Waiting on a future suspends the calling coroutine through a `Channel` rather
than blocking the process, and any number of coroutines may wait on the same
future.

Two consequences worth knowing:

**A task gets its own coroutine context.** That means its own connection
borrowed from the PPA pool, returned automatically when the task ends. It also
means request-scoped state — headers, locale, repository query state — is
**not** inherited. Everything a task needs must arrive through its arguments.

**A task that never suspends finishes eagerly.** Swoole enters the new coroutine
immediately, so a purely computational body completes before `submit()` even
returns. The result is still correct; only the "runs later" intuition does not
hold.

`runningCount(): int` reports how many tasks have not settled yet.

### `DeferredExecutorService` — PHP-FPM, CLI, built-in server

There is no concurrency to be had in a synchronous SAPI, so the contract is
preserved by moving work in *time* rather than running it in parallel:

- **`submit()`** runs the task lazily, at the moment its future is awaited. A
  future that is never awaited still runs — during the drain below.
- **`execute()`** always defers. The queue is drained after
  `fastcgi_finish_request()` has flushed the response, so the client never
  waits.
- **`invokeAll()`** runs everything immediately, in order.

Deferred tasks execute **sequentially in the same worker**: four tasks of 200 ms
occupy the worker for 800 ms after the response was sent. That is the deliberate
trade — no process spawning, no closure serialization, no extra database
connection. It also means the worker is unavailable for new requests during that
time, which is the real cost to watch under load.

Two limits keep counting during the drain: `max_execution_time` and FPM's
`request_terminate_timeout`. A long deferred task can still be killed.

`pendingCount(): int` reports the queue depth; `drain(): void` runs it early.

> `fastcgi_finish_request()` exists only under FPM (LiteSpeed's equivalent is
> used when present). Under CLI and the built-in dev server the response is
> simply sent when the script ends.

---

## Errors

| Thrown by | When |
|---|---|
| `RejectedExecutionException` | executor is shut down, or a coroutine executor is used outside a coroutine |
| `ExecutionException` | the task threw — original available via `getPrevious()` |
| `TimeoutException` | the wait elapsed; the task itself keeps running |
| `CancellationException` | the task was cancelled before producing a result |

The first is raised at submit time, the rest by `Future::get()` — see
[02-future.md](02-future.md).

---

## See also

- [02-future.md](02-future.md) — the handle `submit()` returns
- [03-async.md](03-async.md) — declaring asynchrony on the method instead
- [`ppa/`](../ppa/00-overview.md) — connection pool a task borrows from
