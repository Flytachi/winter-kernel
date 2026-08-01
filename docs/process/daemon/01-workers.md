# Daemon Workers

A daemon worker is an ordinary [`Process`](../00-overview.md) body — it has the
same primitives (`isRunning()`, `sleep()`, `spawn()`, `markBusy()` / `markIdle()`)
and the same signal hooks. This page covers what is specific to running that body
*under a supervisor*: how the body is chosen, how it is created, what it inherits
across the fork, and the one seam you need when it holds a connection.

## Two body forms, fixed priority

The daemon resolves its worker body in this order:

1. **`workerRun(): void`** — if you define it, the daemon itself is the worker and
   this method is the body. Its primitives come from `Process` by inheritance.
2. **`$workerClass`** — otherwise, if set, the daemon supervises instances of that
   standalone `Process` class.
3. Neither → **`DaemonConfigException`** at start.

Use `workerRun()` for a self-contained daemon (one file); use `$workerClass` when
the worker is worth having on its own — it stays independently testable and
runnable (`SendProcess::start()`), and the same class can be supervised by more
than one daemon.

Both forms produce the same process title, `winter-daemon: <Name> worker#{n}`,
where `n` is one-based (`worker#1` is slot #0) and stable across restarts — the
per-worker fleet table shows the underlying zero-based `SLOT`.

## How a worker is created: fork, not Thread

The supervisor is a plain `pcntl` loop with **no event loop running**, so it forks
each worker with `pcntl_fork()`. Forking before any reactor starts is safe — the
child then boots its own clean Swoole coroutine runtime (or a plain fork runtime
without Swoole). Fork is the right tool here, not the Thread
launcher, because supervision needs the direct parent↔child relationship: exact
`waitpid` exit codes, reaping, and per-slot signalling. (Thread's detached launch
re-parents to init and *loses* that relationship; it is used one level up, to send
the **whole daemon** to the background — see [Control](03-control.md#background-dispatch).)

## Dependency injection across the fork

A worker inherits the master's memory copy-on-write, so it already has the same DI
container, the same singletons, the same configuration — `#[Autowired]` is resolved
and `Container::make()` works. For `$workerClass`, the worker is resolved with
`Container::make()` *in the child* (after the fork); for `workerRun()`, the daemon
instance itself carries the body.

### The one caveat: fork-unsafe resources

A fork copies file descriptors. A DB connection, a pool, a socket held in the
container becomes a **shared descriptor** across the master and every worker —
using it from more than one corrupts the protocol. The rule is simple:

> **Open connections in the child, after the fork.**

Two mechanisms make that automatic:

- **`ForkReset`** — framework packages register a reset at bootstrap (a pool
  registers a *reconnect*). The runtime runs every registered reset in the child
  before the body. You write nothing; workers get fresh connections.

  ```php
  // inside a package's bootstrap
  ForkReset::register(fn() => Db::pool()->reconnect());
  ```

- **`afterFork()`** — override it to reset your *own* resources the framework does
  not know about. Call the parent first:

  ```php
  protected function afterFork(): void
  {
      parent::afterFork();  // runs the registered ForkReset handlers
      $this->grpc = null;   // drop the inherited channel; reopen lazily in the body
  }
  ```

A reset must **reconnect in place** (close the old fd, open a new one on the same
object) rather than replace the object — otherwise an already-injected reference
keeps pointing at the stale instance. A lazily-opened resource needs no handler at
all: there is nothing to reset until the child first uses it.

`afterFork()` runs only in a forked worker; a bare foreground `Process` is never
forked and never runs it.

## `spawn()` inside a worker

A worker can still use `spawn()` for I/O concurrency *within itself*, exactly as a
bare process does — bounded by `$concurrency`. The mechanism follows the runtime:

| Runtime | `spawn()` in a worker |
|---|---|
| **Swoole** | real coroutines sharing the worker's memory (and its freshly-reset pool) |
| **FPM / no Swoole** | a `pcntl_fork` per task — isolated child processes, fire-and-forget |

So a Swoole worker fans out coroutines that share its connections; a fork-runtime
worker fans out isolated child processes (its own grandchildren, which it reaps).
See [Process — Concurrency](../02-concurrency.md) for the full model.

## Worker state and scale-down

A worker reports its activity — `IDLE` or `BUSY` — through `markBusy()` /
`markIdle()` (inline units) and in-flight `spawn()` count. The supervisor reads
that heartbeat to build the [fleet view](03-control.md#the-per-worker-view) and,
crucially, to **retire IDLE workers first** when scaling down — so a scale-down
never interrupts work in progress. A BUSY worker chosen for retirement still
drains gracefully; the ordering only decides who goes first. You do not manage any
of this; you only mark your units of work.
