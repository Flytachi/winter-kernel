# Winter Concurrent — Overview

Some work does not belong in the response. Pushing an event to Mixpanel,
sending a welcome e-mail, calling an SMS gateway — the user does not need to
wait 300 ms for any of it, and the request should return as soon as the useful
work is done.

The **Concurrent** unit is how the kernel runs that work: submit a task, get a
handle back, keep serving the request.

```php
Executors::common()->execute(fn() => $mixpanel->track($userId, 'signup'));

return ResponseEntity::ok($user);      // client is not waiting for Mixpanel
```

The API is modelled on `java.util.concurrent` — `Future`, `ExecutorService`,
`Executors`, `CompletableFuture` mean here what they mean there. On top of it
sits `#[Async]`, the equivalent of Spring's `@Async`, which moves the decision
from the call site into the service declaration.

---

## Why an abstraction at all

PHP has no threads. Historically the only way to run something in the
background was to spawn a whole new process: fork or `proc_open`, boot the
framework again, open a fresh database connection, and only then do the work.
That costs about **112 ms and tens of megabytes for a task whose body is
empty** — usually far more than the work itself.

Under Swoole the answer is different: a coroutine costs roughly **12 KB** and a
few microseconds, and a task blocked on a network call simply yields so the
worker keeps serving other requests. Under PHP-FPM there are still no
coroutines, but a task can at least be deferred until *after* the response has
been flushed.

Two runtimes, two mechanisms, one contract. Application code says *what* should
happen in the background; the kernel decides *how*, based on where it is
running.

---

## How the pieces fit together

```
Executors                 ← entry point, picks the backend for the runtime
   │
   ├── ExecutorService    ← the contract: submit / execute / invokeAll
   │      ↑
   │      ├── CoroutineExecutorService   (Swoole)  go() + Channel
   │      ├── DeferredExecutorService    (FPM/CLI) lazy + fastcgi_finish_request
   │      └── FixedExecutorService       (pool)    N-slot semaphore over the above
   │
   └── Future             ← handle on a result
          ↑
          CompletableFuture

#[Async]                  ← declaration-site sugar, generates a proxy that
                            calls the executor for you
```

`Future` and `CompletableFuture` know nothing about Swoole. The executor hands
the future a waiting strategy when it creates it, which is why the same object
works unchanged in both runtimes.

---

## The two runtimes

| | Swoole | PHP-FPM / CLI |
|---|---|---|
| Backend | `CoroutineExecutorService` | `DeferredExecutorService` |
| A task is | a coroutine | a deferred callback |
| Concurrency | real — tasks interleave on I/O | none — tasks run one after another |
| Waiting on a `Future` | suspends the calling coroutine | runs the task right there |
| Fire-and-forget | runs alongside the request | runs after the response is flushed |
| Cost per task | ~12 KB | ~0 |

The contract is identical in both: **the result is always correct, and
fire-and-forget never makes the client wait.** What differs is parallelism,
which FPM cannot provide at all. See [01-executors.md](01-executors.md) for the
exact FPM semantics.

---

## Two ways to use it

Concurrent offers a primitive and, on top of it, an attribute. They are not
alternatives to choose between once — they answer different questions.

**`Executors` — asynchrony is a decision of the caller.**

```php
Executors::common()->execute(fn() => $mixpanel->track($userId, 'signup'));
```

Visible at the call site, works everywhere: with `new`, with `final` classes,
with private methods, with plain closures. This is the right default for
one-off background work.

**`#[Async]` — asynchrony is a property of the service.**

```php
#[Async]
public function track(int $userId, string $event): void { … }
```

Declared once, and every one of the twenty call sites gets it without knowing.
Use it when "this service always works in the background" is part of its
contract. It requires the object to come from the DI container — see
[03-async.md](03-async.md) for the full rule set.

| Situation | Reach for |
|---|---|
| One-off background work in a controller | `Executors::common()` |
| A service that is asynchronous by nature | `#[Async]` |
| Static method, `final` class, manual `new` | `Executors::common()` |
| Fan-out over several external calls | `invokeAll()` |
| Cap the parallelism of a workstream | `Executors::newFixedExecutor(n)` — [05-pools.md](05-pools.md) |

---

## What it costs

Measured on PHP 8.5.8, a single background task with an empty body, start to
result:

| | process per task (the old way) | coroutine |
|---|---|---|
| Full cycle | 112 ms | **1.0 ms** |
| Memory | 3.5 MB (minimal app; a real one is far larger) | **12.3 KB** |

500 concurrent coroutines occupy about 6 MB in one worker.

---

## Pages

| # | File | Contents |
|---|------|----------|
| 00 | this page | What the unit is, the two runtimes, when to use what |
| 01 | [01-executors.md](01-executors.md) | `Executors`, `ExecutorService`, backend behaviour |
| 02 | [02-future.md](02-future.md) | `Future`, `CompletableFuture`, results and failures |
| 03 | [03-async.md](03-async.md) | `#[Async]` — contract, proxying, pitfalls |
| 04 | [04-build.md](04-build.md) | Caches, `call di build`, deployment |
| 05 | [05-pools.md](05-pools.md) | Fixed-size pools — sizing, reject policies, gauges |

## See also

- [`configuration/07-di.md`](../configuration/07-di.md) — the container the proxies are registered in
- [`console/10-di.md`](../console/10-di.md) — the `call di` command
- [`process/00-overview.md`](../process/00-overview.md) — managed workers and supervised fleets that own their own process
