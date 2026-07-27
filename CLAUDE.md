# CLAUDE.md — winter-kernel: Process/Daemon layer handoff

This file orients you (Claude) to the work done on the **Process/Daemon** layer of
`winter-kernel`. Read it fully before touching that layer. It describes what was
built, how it works, why, and the rules to keep.

> **winter-kernel** is a PHP 8.4+ framework kernel (a library, not an app). It runs
> under two runtimes: **Swoole** (coroutines) and **FPM/CLI** (plain processes).
> Namespace root: `Flytachi\Winter\K2\` → `src/`. Tests: `Flytachi\Winter\K2\Tests\` → `tests/`.

---

## 0. Standing rules (do not violate)

- **NEVER touch git.** The user commits/pushes. Plans contain code only — no commit steps.
- **Comments & PHPDoc: English only.** (Discussion with the user can be Russian.)
- **PHPDoc style:** one space between tag/type/name (no column alignment). In code
  examples, use **only real methods** — never invent a method like `$this->handle()`;
  use a comment placeholder (`// ... work ...`) for domain logic.
- **Framework philosophy: mechanism, not policy.** The kernel gives pipeline/hooks;
  the coder decides policy (masking, sanitization, etc.).
- **Encapsulation matters to the user.** Internal machinery must not leak into the
  application-facing API (see §7). This was a repeated, explicit concern.
- Sibling packages (`winter-thread`, `winter-logger`, `winter-di`, `winter-cdo`) are
  **separate repos** pulled via composer. Don't edit them from here unless asked.
- The **dev playground** is `dev/` — a runnable app. Demos: `php dev/call daemon|process <dot.Class> ...`.

---

## 1. What this layer is

A **runtime-agnostic managed-process abstraction**, Java-canonical in feel, mirroring
the existing `src/Concurrent` layer. Two levels:

- **`Process`** — one managed worker: one body, one PID, alive until stopped. For a
  single queue consumer, a single scheduled loop, a leader-elected singleton.
- **`Daemon extends Process`** — a **supervised fleet** of identical worker replicas
  kept alive by a master supervisor (like `nginx`/`php-fpm` master+workers, or a k8s
  Deployment). For a consumer pool, autoscaled workers, crash-isolated fan-out.

The developer writes config + a body; the framework supplies the runtime (coroutines
or forks), concurrency, cooperative cancellation, a signal contract, guaranteed
teardown, a crash-safe singleton, and (for Daemon) forking/reaping, restart with
back-off, graceful drain, autoscaling with damping, and a liveness watchdog.

### Location & namespaces

| Path | Namespace | What |
|---|---|---|
| `src/Process/` | `Flytachi\Winter\K2\Process` | **the canonical layer (this work)** |
| `src/Process/Daemon/` | `…\Process\Daemon` | Daemon + supervision + policies + slot model |
| `src/Process/Engine/` | `…\Process\Engine` | runtime backends (Swoole/Sync) |
| `src/Process/Internal/` | `…\Process\Internal` | private-method traits (encapsulation) |
| `src/Old/Process/` | `…\Old\Process` | **OLD ThreadDaemon system, archived; the user will delete it.** Do not build on it. |

> History: this layer was developed in `src/Dev/Process/` (`…\Dev\Process`), then
> promoted to canonical `src/Process/`; the old `src/Process/` moved to `src/Old/Process/`.
> If you see `Dev\Process` anywhere it is stale — it should be `Process`.

---

## 2. `Process` — developer API

```php
final class EmailConsumer extends Process
{
    #[Autowired] private MailQueue $queue;

    protected int $concurrency = 50;   // cap on spawn() (0 = unlimited)
    protected float $grace = 0.0;       // drain deadline on stop (0 = wait forever)
    protected ?string $processTitle = null; // ps title (default = short class name)

    public function run(): void          // the body — loop on isRunning()
    {
        while ($this->isRunning()) {
            $job = $this->queue->pop(timeout: 1.0);
            if ($job === null) { continue; }
            $this->markBusy();
            // ... process $job ...
            $this->markIdle();
        }
    }
}
```

**Primitives** (use inside `run()`; `final protected`): `isRunning(): bool`,
`sleep(float)` (interruptible — throws `InterruptedException` if an IDLE wait is
woken by a stop), `spawn(callable): Future` (bounded by `$concurrency`;
structured-concurrency drain on exit), `requestStop()`, `markBusy()/markIdle()`,
`activity(): Activity`, `touch()` (explicit liveness beat — for a daemon watchdog).

**Hooks** (`protected`, override to react; the framework calls them):
`onTerminate` (SIGTERM), `onInterrupt` (SIGINT), `onReload` (SIGHUP — does NOT stop),
`onUser1`/`onUser2` (SIGUSR1/2), `onShutdown` (guaranteed teardown, runs once),
`afterFork` (reset fork-unsafe resources in a forked worker — see §5),
`buildProcessTitle`/`titleName` (override to customise the `ps` title).

**Control** (`final public static`; usually from CLI): `start(): void` (foreground,
blocks), `dispatch(?string $output = '/dev/null'): int` (detached background, returns
PID), `status(bool $usage = false): ?ProcessStatus`, `stop(): bool` (sends SIGTERM).

**Singleton per class** — one running instance per class, guarded by a crash-safe
`flock` (never a PID file). A second `start()`/`dispatch()` throws
`ProcessAlreadyRunningException`. Liveness via `posix_getpgid` (needs no permission →
cross-user safe). `status()` is a **pure read** — it never deletes the record.

**Stop semantics:** SIGTERM/SIGINT → `isRunning()` flips false (cooperative). An IDLE
`sleep()` is interrupted at once (coroutine cancel / flag). A BUSY unit drains
(finishes its current unit). Past `grace` (>0) → force exit. `grace = 0` = wait
forever. A **repeated** stop on a bare process is **ignored** (only `grace` timer or
external SIGKILL forces) — the "second signal forces" behaviour belongs to Daemon.

---

## 3. `Daemon` — developer API

```php
final class Emails extends Daemon
{
    protected int $replicas = 3;                     // baseline fleet size
    protected float $grace = 30.0;                    // master drain deadline (k8s parity; 0 = forever)
    protected float $livenessTimeout = 0.0;           // watchdog off by default

    // Body — ONE of two (priority: workerRun ▸ $workerClass):
    protected function workerRun(): void              // inline: the daemon IS the worker
    {
        while ($this->isRunning()) { $this->markBusy(); /* ... */ $this->markIdle(); }
    }
    // protected ?string $workerClass = SendProcess::class; // OR supervise an external Process class

    // Optional policy (all have sane defaults):
    protected function desiredReplicas(): int { return min(16, intdiv($this->queue->depth(), 100)); }
    protected function scaling(): ScalingPolicy { return ScalingPolicy::default(); }
    protected function restart(): RestartPolicy { return RestartPolicy::default(); }

    // Optional master hooks:
    protected function onWorkerStart(int $slot, int $pid): void {}
    protected function onWorkerExit(int $slot, int $pid, bool $crashed): void {}
    protected function onScale(int $from, int $to): void {}
    protected function tick(): void {}                // periodic on master (~scaleInterval)
}
```

The **manager and the unit of work are separate concerns** (like an executor and its
task). `workerRun()` = self-typed (daemon is also the worker). `$workerClass` = a
standalone `Process` you can also run solo (`SendProcess::start()`) and supervise. If
neither is set, each worker fails on fork with `DaemonConfigException`.

**Control** = same 4 verbs as Process (`start`/`dispatch`/`status`/`stop`); `status()`
returns a **`DaemonStatus`** (a `ProcessStatus` + `restarts` + `workers[]`, one
`WorkerStatus` per non-empty slot).

`ScalingPolicy` (readonly, non-final, `::default()`): `scaleInterval=1.0`,
`scaleUpDelay=0.0`, `scaleDownStabilization=60.0`, `cooldown=3.0`, `scaleStep=0`.
`RestartPolicy` (readonly, non-final, `::default()`): `mode` (`RestartMode` enum:
`ALWAYS`/`ON_FAILURE`(default)/`NEVER`), `maxRestarts=0` (0=∞), `backoff=1.0`.

---

## 4. How the Daemon works internally (the hard part)

The master is a **plain `pcntl` process with no event loop of its own** — it forks
workers and reaps them with `pcntl_waitpid`. Forking before any reactor starts is
safe; each worker child then boots its own clean `Coroutine\run` (Swoole) or plain
runtime. Workers are created with **`pcntl_fork`, not the Thread launcher** —
supervision needs the direct parent↔child `waitpid` relationship. (The Thread
launcher is used one level up, in `dispatch()`, to send the whole master to the
background.)

### Slot state machine (`SlotState` enum, `Slot` mutable model)

```
 EMPTY ─fork─► STARTING ─heartbeat─► RUNNING ─exit─► RESTARTING ─backoff─► (fork)
                            │           │  ▲                       │
                     retire │    retire │  │ un-retire             │ give up
                            ▼           ▼  │                       ▼
                        RETIRING ◄───────┘ │                     RETIRED / EMPTY
                            │ deadline/force
                            ▼
                        KILLING ─reaped─► EMPTY
```

- `isCommitted()` = STARTING/RUNNING/RESTARTING/**RETIRED** (the size reconcile drives
  to desired). `isAlive()` = STARTING/RUNNING/RETIRING/KILLING (has a live PID).
- **The slot state is the intent marker** that keeps the restart policy and the
  autoscaler from fighting: a worker retired on purpose (scale-down/stop) is `RETIRING`
  → never restarted; one that died on its own is handled by the restart policy.

### The reconcile loop (single authority over fleet size)

Each `scaleInterval`: `tick()` → compute damped `desiredReplicas()` → drive the
committed fleet toward it (up: fork; down: retire) by at most `scaleStep`, gated by
`cooldown`.

- **Restart:** an unexpected death (`RUNNING`→exit) → `RestartPolicy`. If it restarts,
  the slot goes `RESTARTING` (back-off `base·2^(n-1)`, cap 30s) then re-forks into the
  **same slot** (so `worker#{n}` is stable). `maxRestarts` exceeded → daemon `FAILED`,
  stops. If policy declines (`NEVER`, or a clean exit under `ON_FAILURE`) → **`RETIRED`
  terminal state** (NOT freed — else reconcile would refill it instantly, bypassing
  back-off → crash-loop). This RETIRED behaviour is a deliberate fix; do not "simplify"
  it back to `free()`.
- **Scale-down:** mark victims `RETIRING` (graceful SIGTERM drain), **IDLE-first**
  (best-effort from the heartbeat; a BUSY victim still drains). RETIRED/RESTARTING
  slots are shed first (free). Anti-flap: a scale-up first un-retires still-draining
  workers before forking new ones.
- **Scaling damping (`ScalingPolicy`):** up = react to the sustained floor over
  `scaleUpDelay`; down = shrink only to the **high-water demand over
  `scaleDownStabilization`** (a transient dip sheds nothing). `scaleStep` caps the
  magnitude per action; `cooldown` the frequency. Signal, not command.

### Stop sequence

SIGTERM to master → **freeze reconcile** (state STOPPING) FIRST → retire the whole
fleet (parallel SIGTERM) → each drains by its deadline (`grace`; 0=∞) → SIGKILL
stragglers past deadline → after the fleet is empty run `onShutdown()`, delete the
record, release the flock, exit `TERMINATED`. A **second** stop signal collapses all
deadlines → immediate SIGKILL (operator's "stop now").

### Watchdog (liveness)

Every worker writes a **monotonic heartbeat** (`ProcessStatus.heartbeatAt`) each engine
tick (~1s) to a per-slot store record keyed `<Daemon>#<slot>`. The master reads it for
(a) the fleet view and (b) IDLE-first victim selection. If `livenessTimeout > 0` and a
RUNNING/STARTING worker's heartbeat goes silent past it → SIGKILL → reap → restart
(crash path). Catches a wedged worker (deadlock, hung I/O) that a plain PID check
misses. Under FPM, a long BUSY unit that never yields won't heartbeat → either raise
the timeout or call `touch()` inside it.

### Titles

`winter-process: <Name>` · `winter-daemon: <Name> master` · `winter-daemon: <Name>
worker#{n}` where **n is one-based** (`worker#1` = slot #0). Shared `winter-daemon:`
prefix → `pkill -f 'winter-daemon: <Name>'` kills the whole family.

---

## 5. Fork-safety (`afterFork` / `ForkReset`)

A fork copies the parent's memory **including open fds** (DB connections, pools,
sockets). Shared fds corrupt the wire protocol. Rule: **open connections in the child**.

- `Process::afterFork()` runs in a forked worker before `run()`. Default:
  `ForkReset::runAll()` — runs every framework-registered reset. Override to reset your
  own resources (call `parent::afterFork()` first).
- Framework packages register a reset at bootstrap. **`Kernel::init()` registers**
  `ForkReset::register(fn() => PpaConnectionPool::reset())` — so daemon workers get
  fresh DB connections automatically. A reset must **reconnect in place** (not replace
  the object — an injected reference would go stale) or the pool must be lazy.

---

## 6. Runtimes (engines)

`Engines::common($concurrency, $grace)` picks by `extension_loaded('swoole')`:

- **`SwooleEngine`** — body runs in `Coroutine\run`; `spawn()` = real coroutines
  (shared memory, coroutine semaphore); `sleep()` non-blocking; stop cancels the body
  coroutine → interruptible.
- **`SyncEngine`** — FPM/plain; `spawn()` = `pcntl_fork` per task (isolated,
  fire-and-forget, `Future` = settled placeholder); `sleep()` interruptible in chunks;
  `SIGALRM` force-exit at grace.

Both implement `ProcessEngine`. **Two bugs fixed here — do not reintroduce:**
1. **Reactor hang:** `SwooleEngine::enter()` MUST unregister signals
   (`\Swoole\Process::signal($signo, null)`) + clear timers in its `finally`, else
   `Coroutine\run` never returns after the body ends (hangs forever with `grace=0`).
2. **grace>0 drain freeze:** in `SwooleEngine::requestStop()`, arm the grace
   `Timer::after` **BEFORE** `Coroutine::cancel($bodyCid)`. Arming the timer after the
   cancel swallows the pending resume → the body freezes until the timer fires (drain
   would always wait the full grace). Order matters.

---

## 7. Encapsulation design (important — the user cares deeply)

Internal machinery must not appear in the application-facing API of a subclass. PHP has
no package-private, so:

- **`Internal\SingletonLock` trait** (private `acquireLock`/`releaseLock`/`lockPath` +
  `$lockHandle`) — `use`d by BOTH `Process` and `Daemon`. Private trait methods are
  invisible to app subclasses (`class Foo extends Process` can't call them) yet each
  framework class gets its own copy.
- **`Daemon\SupervisesFleet` trait** — the ENTIRE supervision loop (reconcile,
  reap, watchdog, scaling, stop, slot transitions, `superviseFleet()`, `snapshot()`,
  `backoff()`, …), all **`private`**, `use`d by `Daemon`. There is **no `Supervisor`
  class** — it was merged into this trait so nothing leaks. The daemon IS the supervisor
  in the master process.
- Daemon's internal accessors (`replicas`, `computeDesired`, `scalingPolicy`,
  `restartPolicy`, `graceSeconds`, `livenessTimeout`, `bootWorker`, `workerRecord`,
  `clearWorkerRecord`, `fire*`) are all **`private`** — the trait calls them via `$this`.
- `applyProcessTitle` and `Daemon::workerTitle` are **`private`**.
- `Process::runWorker` is **`protected`** (not public): a daemon boots an external
  `$workerClass` worker (a sibling Process) via cross-instance protected access
  (allowed in PHP because it's declared in the common base `Process`).
- **Kept `protected @internal` by necessity** (not leaks): `key()`, `store()`,
  `ensureNotRunning()` — required protected because `status()`/`stop()` reach them via
  late-static-binding (`static::key()`) and Daemon reuses them; they are benign.
- `run()` is public — it is the contract method the developer implements (like
  `Runnable::run`), not internal machinery.

**Override points that stay `protected` (intended API):** `run`, all `on*` hooks,
`afterFork`, `buildProcessTitle`, `titleName` (Process); `workerRun`, `desiredReplicas`,
`scaling`, `restart`, `onWorkerStart/Exit`, `onScale`, `tick` (Daemon).

If you add anything the supervision trait needs from Daemon, make it **private** and
call it via `$this->` from the trait.

---

## 8. CLI

- `call process <dot.Class> [start [-d] | stop | status [-v]]` (alias `proc`). Lists
  only bare processes.
- `call daemon <dot.Class> [start [-d] | stop | status [-v]]` (alias `dmn`). Lists only
  daemons; `status` shows the per-worker fleet table (SLOT/PID/STATE/ACT/UPTIME/RESTARTS).

Dot notation → FQCN: `main.process.Emails` → `Main\Process\Emails`. Tab-completion is
in `console/Command/Complete.php`. Commands: `console/Command/{Process,Daemon}.php`.

---

## 9. Tests

- **Unit** (`tests/Process/`, ~96 tests): enums, value objects, JSON serialization,
  config resolution/clamping, `ForkReset`, titles, and the supervision **decision
  algorithms** (`SupervisesFleetTest` — backoff, damping/`windowExtreme`, `pickVictims`
  IDLE-first, slot counts) via reflection, deterministic, no forks.
- **Integration** (`tests/Process/Integration/`, 17 tests, `#[Group('integration')]` →
  excluded from the default run): real fork/swoole/signals. `IntegrationCase` boots a
  temp-storage kernel, forks a real Process/Daemon child, observes via the shared store
  + a `WK_MARKER` file, sends real signals. Covers: fork N replicas, graceful stop (no
  orphans), restart-in-slot, maxRestarts→FAILED, NEVER→retired, watchdog, autoscale
  up→down, singleton, 2nd-signal force, per-worker activity, SIGHUP=reload, external
  `$workerClass` path, and all Process signal hooks.

**Run:** `vendor/bin/phpunit` (full suite, **1346 tests** — integration excluded).
Integration: `vendor/bin/phpunit --group integration tests/Process/Integration` (needs
`pcntl`+`posix`; runs under Swoole here; ~20s).

**Test conventions:**
- Method names are `test_snake_case` (project convention; PSR-12 flags camelCase — ignore).
- Reflection needs no `setAccessible` (PHP 8.1+). For a **private property declared in a
  trait**, reflect via the **declaring class** (`new ReflectionProperty(Daemon::class,
  'slots')`), NOT the subclass (private parent props aren't visible via a child class).
  Private methods reflect fine via the instance.
- Forked children in tests end with `posix_kill(getmypid(), SIGKILL)` to skip PHPUnit's
  shutdown (avoids polluting output).
- `IntegrationCase::setUp` resets `KernelStore`'s `$runnable`/`$storages`/`$volatiles`
  caches (via reflection) — `Kernel::runnable()` caches `FileStorage` by name against
  the path it was first built with, so a reused fixture class would otherwise read a
  previous test's deleted temp dir.

Dev demos (runnable): `dev/main/Process/*.php` (StableDaemon, CrashDaemon, FleetDaemon
[`$workerClass`], AutoscaleDaemon, NeverDaemon, HungDaemon, SendProc, …).

---

## 10. Docs

`docs/process/` (`00-overview`, `01-lifecycle`, `02-concurrency`, `03-control`) and
`docs/process/daemon/` (`00-overview`, `01-workers`, `02-autoscaling`, `03-control`).
Mature, behaviour-focused, English, verified against the code. Keep them accurate if you
change the API.

---

## 11. Status

**Done & verified (live under Swoole + full test suite):** Process (lifecycle, dual
runtime, signals, drain, grace/force, spawn/concurrency, singleton, JSON/security),
Daemon (worker-typed + inline, fork+DI+`afterFork`, reconcile+slot-state, retire/
scale-down IDLE-first, scaling damping, restart+backoff+maxRestarts→FAILED, full stop
sequence, watchdog, per-worker status, hooks, titles), full encapsulation (traits),
PHPDoc, docs, unit + integration tests. `dispatch()` works from inside a Swoole
coroutine (fixed in the `winter-thread` package: `AdaptiveLauncher`/`SwooleLauncher`).

**Not done (future phases):** phase 3 — templates (worker-pool, SMPP, WebSocket);
phase 4 — web status (a controller reading the same store; `DaemonStatus` is already
JSON-serializable). `src/Old/Process` is to be **deleted by the user**.

**Known minor limitations:** `maxRestarts` is cumulative (not "consecutive"); a
STARTING worker that hangs before its first heartbeat relies on the watchdog with a
timeout; SIGKILL of an FPM worker can orphan its `spawn` grandchildren (only on the
past-grace force path).

---

## 12. Gotchas — things already solved, don't reintroduce

- Force-exit must run cleanup: a `register_shutdown_function` backstop deletes the store
  record even on `exit()` (finally alone is skipped on force-exit).
- `status()` uses `posix_getpgid` (not `posix_kill($pid,0)` — which needs permission and
  fails cross-user) and is a **pure read** (no delete) — else a cross-user status check
  could evict a live process's record.
- Activity is a **backed** enum (`Activity: string`) and status objects are
  `JsonSerializable` — a pure enum made `json_encode` return `false`.
- The two SwooleEngine bugs in §6.
- The maxRestarts→FAILED slot-free ordering and the NEVER→RETIRED terminal state in §4.
- Daemon status must heartbeat-persist (~1s) in the loop, not only on fleet-size changes,
  or activity/STARTING→RUNNING never reach the store.
