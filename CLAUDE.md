# CLAUDE.md — winter-kernel: Process/Daemon + ConnectionPool handoff

This file orients you (Claude) to the work done on `winter-kernel`: the
**Process/Daemon** layer (§1–§12), the **ConnectionPool** layer (§13), the
**project layout / static files** (§14), and the **`WinterApplication` starter** (§15).
Read the relevant part fully before touching it. It describes what was built, how it
works, why, and the rules to keep.

> **winter-kernel** is a PHP 8.4+ framework kernel (a library, not an app). It runs
> under two runtimes: **Swoole** (coroutines) and **FPM/CLI** (plain processes).
> Namespace root: `Flytachi\Winter\Kernel\` → `src/`. Tests: `Flytachi\Winter\Kernel\Tests\` → `tests/`.

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
| `src/Process/Stereotype/` | `…\Process\Stereotype` | **`Process` and `Daemon` — the two classes an application extends** |
| `src/Process/` | `Flytachi\Winter\Kernel\Process` | shared model: `Activity`, `ProcessStatus`, `ProcessState`, `ProcessStore`, `ForkReset` |
| `src/Process/Daemon/` | `…\Process\Daemon` | supervision + policies + slot model (`SupervisesFleet`, `Slot`, `ScalingPolicy`, …) |
| `src/Process/Engine/` | `…\Process\Engine` | runtime backends (Swoole/Sync) |
| `src/Process/Internal/` | `…\Process\Internal` | private-method traits (encapsulation) |

> The split is the point: `Process/Stereotype/` holds what you extend, everything beside
> it is machinery. Before the 2026-08-02 restructure `Daemon` sat inside
> `Process/Daemon/` next to `SupervisesFleet`, and telling the extension point from the
> internals meant reading the source. See `doc/2026-08-02-restructure-design.md`.
>
> History: this layer was developed in `src/Dev/Process/`, then promoted to
> `src/Process/`; the archived `src/Old/Process/` has since been deleted. If you see
> `Dev\Process` or `Old\Process` anywhere it is stale.

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

### The extension surface (added 2026-08-02)

Encapsulation above governs *members*; this governs *classes*. Two rules, both enforced
by tests — `tests/Architecture/{StereotypeLayoutTest,ExtensionSurfaceTest}.php` and
`tests/Console/CommandSurfaceTest.php`.

**1. What an application extends lives in `<Layer>/Stereotype/`.**

| | |
|---|---|
| `src/Http/Stereotype/` | `Controller`, `ControllerInterface`, `Middleware`, `ExceptionResponseBase` |
| `src/Ppa/Stereotype/` | `Repository`, `RepositoryCrud`, `RepositoryView` |
| `src/Process/Stereotype/` | `Process`, `Daemon` |
| `src/Schedule/Stereotype/` | `Scheduler` |
| `console/Stereotype/` | `CmdCustom`, `CmdCustomInterface` |

Every `Stereotype/` belongs to a layer — there is no orphan one at the root. The layer
keeps its machinery beside, not inside, that directory, so a layer can be extracted into
its own package **together with its extension point**.

The test for `implements`-only contracts is that they stay put: `MiddlewareInterface`
lives in `Http/Middleware/` and `HealthContributor` in `Http/Health/`, because
**`Stereotype/` is for `extends`, not for `implements`.**

> **There is no `Service` base class, and do not reintroduce one.** It was an empty
> `abstract class` — Spring's `@Service` **annotation** transliterated into inheritance.
> Java made it an annotation precisely because Java, like PHP, allows a single parent:
> spending that slot on a semantic marker is a bad trade. Nothing in the kernel ever read
> it (the container resolves by class name, lifetime comes from `#[Singleton]` and
> friends), and in practice it forced `class X extends Service implements XInterface` —
> the real contract pushed into an interface because the parent slot was already gone.
> A service is a plain class. If a role marker is ever wanted, it must arrive as an
> attribute **with a mechanism behind it** (implying `#[Singleton]`, or serving as an AOP
> pointcut target) — a marker nothing reads is policy, not mechanism.

**2. A class is `final` unless someone wrote down why not.**

`final` is the only thing that removes a class from an IDE's `extends` completion, so an
open class is public API whether or not that was intended. `ExtensionSurfaceTest::OPEN`
is the single place openness is declared, and each entry carries its reason. Adding a
non-final class without an entry fails the suite.

Exceptions are open **as a category** (extending `ClientError` in an application is
normal) and are skipped by the test via `Throwable`.

> **Do not decide this by grep.** `ExceptionResponseBase` has no subclass in this
> repository and still must stay open — `#[AdviceException]` handlers extend it, and they
> live in applications. Check documented contracts (`#[Enable*]`, `#[Advice*]`, PHPDoc
> examples), not usage counts.

Also: built-in `console/Command/*` are `final` because two of them (`Process`, `Daemon`)
share a short name with a real stereotype, and an open command made both appear in
completion. That was the original complaint; `final` fixed it without renaming anything.

### DI scopes — one rule, enforced at boot (added 2026-08-03)

> **A class may hold a reference to a shorter-lived object only if it does not outlive it.**

Injected properties resolve **once, when the holder is built**. So a `#[Singleton]`
holding a `#[Request]` bean freezes the first request's instance for the worker's
lifetime — every later request keeps seeing it, with no error and nothing in the log.
Measured live: three users, all three served as the first one.

The reach is **transitive** — a singleton freezes its whole dependency subtree, so
`#[Singleton] → plain service → #[Request]` leaks identically. Also measured, not reasoned.

`Collector\ScopeGraphCollector` gathers the dependency graph during the single scan pass;
`assertNoFrozenRequestScope()` walks it in `bootstrap()` and throws `ScopeConflictException`
naming the whole path. Cost: ~0.01 ms at boot, nothing per request. Cycle-guarded.

This mirrors Spring's *safe* branch: there, `@RequestScope` injects a scoped proxy, and a
raw `@Scope("request")` without one fails at startup. Spring never leaks silently because
its singletons are built eagerly, before any request exists, so the resolution simply has
nothing to capture. Ours are built lazily — during the first request — which is exactly
why the capture succeeds and then rots. The boot check restores the guarantee.

`#[Request]` outside HTTP: a worker's body is **one** coroutine, so a request-scoped bean
resolved there would outlive every job. `Process::markBusy()` — which already marks where a
unit of work starts — therefore also ends the request scope, via
`Container::flushRequestScope()`. Singletons are untouched, and a body that never marks a
unit resets nothing. Measured before the fix: four iterations, one object, each seeing what
the one before it wrote.

Consequences worth remembering:
- `#[Singleton]` is **per worker process**, not per application: 4 workers = 4 instances.
  Counters and caches in singleton fields disagree with themselves.
- Repositories are safe to share because their query state lives in the coroutine context,
  not the object — hence `#[Singleton]` in the generated template (§13-adjacent, `call make -r`).
- `Http\Stereotype\Controller::__construct()` is `final`: no constructor means no natural
  place to stash request state, which removes half the trap before it appears.

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
- **Integration** (`tests/Process/Integration/`, 18 tests, `#[Group('integration')]` →
  excluded from the default run): real fork/swoole/signals. `IntegrationCase` boots a
  temp-storage kernel, forks a real Process/Daemon child, observes via the shared store
  + a `WK_MARKER` file, sends real signals. Covers: fork N replicas, graceful stop (no
  orphans), restart-in-slot, maxRestarts→FAILED, NEVER→retired, watchdog, autoscale
  up→down, singleton, 2nd-signal force, per-worker activity, SIGHUP=reload, external
  `$workerClass` path, and all Process signal hooks.
- **Architecture** (`tests/Architecture/`, `tests/Console/`): guards that hold the
  structure in place rather than testing behaviour — stereotype addresses, the absent
  generation suffix in the namespace, the `final` surface, and that every generator
  template imports a class that exists. They fail on drift, which is their whole job.

**Run:** `vendor/bin/phpunit` (full suite, **1605 tests** — integration excluded).
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

`docs/` is the in-repo reference, English, behaviour-focused, verified against the code —
routing and request binding, responses, PPA, processes and daemons, scheduling, console,
configuration, plus `starter/00-quickstart.md`. `README.md` is the install-and-run entry
point. **Keep both accurate when you change an API**: they were audited class-by-class
via reflection, and every framework symbol they name resolves.

The user's public documentation site lives in a separate repository and is his own
concern — do not try to keep it in sync from here.

`doc-new/` no longer exists. It held working design notes while the ConnectionPool,
layout and starter work was in flight; those are now §13, §14 and §15 of this file.
Do not recreate it — design rationale belongs here, user-facing prose in `docs/`.

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
JSON-serializable).

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
- **`wKernelRunner` is load-bearing — do not delete it.** It looks like a leftover (it is
  a bare file in the repo root, shipped as composer `"bin"`), but it is the child side of
  `dispatch()`: `Process::dispatch()` → `Thread` launcher → `php vendor/bin/wKernelRunner
  --detach` → composer bin proxy → the project's `bootstrap.php` →
  `WinterApplication::discoverAppClass()` → `::executor($argv)` → `bootstrap()` →
  `AdaptiveRunner`. Detaching cannot be a fork (the parent may be a Swoole worker whose
  reactor must not be duplicated), so a **fresh PHP process** must boot the app again.
  Two traps: the runner resolves the project root as `dirname(__DIR__, 3)`, so it must be
  **copied, never symlinked** (PHP resolves `__DIR__` through symlinks); and `WINTER_KEY`
  must reach `$_ENV` (`env()` reads `$_ENV` only, never `getenv()`) or the child rejects
  the signed payload. Covered by `tests/Process/Integration/DispatchRunnerTest.php` —
  every other Process test forks directly and never executes the runner, which is why a
  broken runner once went unnoticed.
- Both thread launchers spawn the same `php <runnerPath>` child; only the shell call
  differs (`Coroutine\System::exec` inside a coroutine, `proc_open` elsewhere). There is
  no `Swoole\Process` path — Swoole refuses one while its async-io threads are up.

---

## 13. `ConnectionPool` — the "HikariCP-lite" layer

### The problem it solves

Under FPM every request got a **fresh** connection, so a database outage healed itself:
the process died, the next one reconnected. A long-lived Swoole worker keeps connections
in memory, and a plain `Swoole\ConnectionPool` is a **dumb channel** (`get`/`put`, zero
maintenance): after the DB comes back, the dead sockets are still in the pool and
`put($cdo)` returns each corpse for the next borrower. This layer restores FPM-level
self-healing without paying per-borrow.

> **Do NOT "fix" this with a `SELECT 1` on every borrow.** That was tried and reverted —
> it adds a round-trip to every query and churns healthy connections. HikariCP does not
> do it either. The mechanism below is the idle-gate; keep it.

### Location & shape

| Path | What |
|---|---|
| `src/ConnectionPool/` | the generic, **self-contained** module (no PPA/CDO inside — it can be `git mv`d into its own package) |
| `src/Ppa/Pool/` | the PPA adapter that wires CDO into it |

Module: `ConnectionPool` (coroutine, `Swoole\Coroutine\Channel`) · `SingleConnection`
(FPM/non-coroutine, **no** Channel) · `ConnectionFactory` (create/validate/close) ·
`PoolEntry` · `PoolPolicy` · `PoolException`. Both take an injectable `Closure $clock`
— that is the seam that makes idle/lifetime logic unit-testable without a live DB.

`Runtime::isSwooleCoroutine()` picks the path: coroutine → `ConnectionPool`,
everything else → `SingleConnection`. **`SingleConnection` is not coroutine-safe**; it
is only ever reached off the coroutine path, and that invariant is what keeps it simple.

### `PoolPolicy` knobs

| Knob | Default | Meaning |
|---|---|---|
| `maximumPoolSize` | 10 | upper bound |
| `connectionTimeout` | 15.0 | wait for a free connection, then fail fast |
| `maxLifetime` | 1800.0 | rotate by age (`0` = never); jittered by `maxLifetimeJitter` (0.1) |
| `aliveBypassWindow` | 0.5 | **idle-gate**: idle less than this ⇒ skip the probe |
| `housekeepingInterval` | 30.0 | background pass period (clamped to ≥1s) |
| `keepaliveTime` | 0.0 (off) | probe long-idle connections in the background |
| `idleTimeout` | 0.0 (off) | close idle connections down to `minimumIdle` |
| `minimumIdle` | 0 (lazy) | warm floor |

The last three are **off by default**, and `housekeepingEnabled()` gates the timer — an
unconfigured pool never arms one. Housekeeping is **Swoole-only** (it needs a timer);
`SingleConnection` gets idle-gate + `maxLifetime` on `get()` and nothing else.

### `aliveBypassWindow` — the point of the whole design

Bigger window = *fewer* probes = **weaker** healing (the intuition runs backwards, so
read it twice). At 0.5s a hot connection reused within the window pays nothing, while a
connection that sat through an outage is always probed before it is handed out. Do not
raise the default to "reduce overhead" — hot connections already pay zero.

### PPA wiring (`src/Ppa/Pool/`)

- `CdoConnectionFactory` — pools the **config instance**, not the raw CDO (winter-cdo's
  config owns the connection): `create` = `new $configClass` + `connect()`,
  `validate` = `ping()`, `close` = `disconnect()`.
- `PpaConnectionPool` keeps the public API it always had (`db`/`getConfigDb`/
  `showDbConfigs`/`reset`) — repositories were not touched.
- Knobs are per config via `PpaPoolConfigInterface` + defaults in `PpaPoolTrait`, so a
  config using the trait never breaks when knobs are added. **Add new knobs the same
  way** (interface method + trait default), never as a bare interface method.

### Evict on connection loss (no retry — deliberate)

`ConnectionLoss` separates a **dead socket** (SQLSTATE class `08`, PG `57P01/02/03`,
MySQL driver codes 2006/2013/2055) from a **rejected query** (`23xxx`, `42xxx`,
deadlock) by walking the `previous` chain — CDO wraps the original `PDOException`.
Only the first evicts; churning the pool on every constraint violation would be a bug.

`PpaConnectionPool::reportFailure($configClass, $e)` is called from the 12 catch blocks
that already existed in `RepositoryCrudTrait`/`RepositoryViewTrait`. On a loss it flips
`BorrowedConnection::$dead` (so the coroutine's `defer` evicts instead of releasing) and
drops the entry from the coroutine context, so the next query — including the next one
in the same request — borrows a fresh connection.

**Never add a retry here.** The pool cannot know what ran: the break may have happened
*after* the server applied the write (replay ⇒ duplicate), and replaying one statement
of an interrupted transaction is meaningless. One request fails; the connection dies.

### Observability

`PpaConnectionPool::stats()` → per-config `{total, idle, active, maximum}`. Two consumers:

1. **Actuator** — folded into the `db` component of `/actuator/health` (one entry per
   datasource carrying both reachability and `pool`), not a separate component.
2. **`call db pool`** — reads per-worker records published by `PoolTelemetry` to
   `Kernel::runnable('ppa.pool', false)`, the same store indirection `call process
   status` uses (the CLI is a different process and can never see a worker's memory).
   Interval via `PPA_POOL_TELEMETRY` (default 5s, `0` = off); records carry a TTL of
   three intervals so a dead worker's record expires by itself.

   **Nothing is armed until there is something to report.** `workerStart` only calls
   `PoolTelemetry::enable($workerId)` (marks the worker eligible); the timer starts from
   `PpaConnectionPool::pool()` on the **first pool**, via `arm()` — the same lazy shape
   the pool uses for its own housekeeper. `arm()` is a no-op where `enable()` never ran,
   which is what keeps a daemon worker or a CLI process from publishing. `stop()` reaches
   for the store only if something was actually written (`$published`), and
   `PpaConnectionPool::reset()` calls `forget()` so a forked child cannot publish under
   its parent's worker id.

   That last pair is a fix, not decoration: `stop()` used to guard on "is the timer
   armed", which was true in *every* worker, so the shutdown path called
   `store()->del()` — and `new FileStorage(...)` mkdirs its folder — leaving an empty
   `storage/runnable/ppa.pool/` in applications with no datasource at all. Do not
   re-guard on the timer.

Numbers are **per worker** (each has its own pool, like HikariCP per-JVM). Saturation is
therefore counted per worker, never derived from fleet sums — a summed pool can look
roomy while one worker is fully blocked.

### Gotchas — already solved, don't reintroduce

- **`reset()` must `abandon()` each pool.** A `Timer::tick` callback holds a reference to
  its pool, so a merely dereferenced pool stays alive and keeps maintaining connections
  the process no longer owns. `abandon()` clears the timer and drops references
  **without closing sockets** — a forked child must never close an inherited fd.
- **Keepalive must not touch `lastUsedAt`.** It measures application idleness; resetting
  it would make `idleTimeout` never fire.
- **`make()` reserves the slot before connecting** (`++$total` then `create()`, rollback
  in `catch`) so concurrent borrows cannot over-provision past `maximumPoolSize`.
- **`PoolTelemetry` uses `Kernel::runnable($name, false)`** — non-hashed keys, because
  the CLI enumerates records with `keys()` and feeds them back to `read()`; with hashing
  those are HMACs and would be hashed a second time.
- SQLite: the pool runs but an embedded DB has no connection to lose; use
  `poolMaxConnections: 1` (and `maxLifetime: 0` for `:memory:`, where every connection is
  a *separate* database and rotation would destroy the data).

### Tests

`tests/ConnectionPool/` (module: pool, `SingleConnection`, housekeeper decisions via
reflection under a controllable clock) and `tests/Ppa/Pool/` (classifier, `reportFailure`
in a real coroutine, telemetry store round-trip, actuator merge). All deterministic, no
live DB. Live drivers: `tests/Integration/Pool/` under `#[Group('pool')]`, enabled by
`PG_TEST_DSN` / `MYSQL_TEST_DSN` / `MARIADB_TEST_DSN`.

Design history: this section is the record; the working notes it came from are gone.

---

## 14. Project layout & static files

### Layout

```
resources/
  static/      web assets — served by Swoole, see below
  views/       view files — ResponseView's default root
storage/       logs, cache, runnable records
```

`views`, not `templates`, on purpose: inside {@see ResponseView} a *template* is the
**layout** (it receives `$content`) and a *resource* is the **page**. Both live under
the same root, so naming that root after either role would be wrong — `views` covers
both, with layouts conventionally under `views/layouts`. (Spring uses `templates/`,
but it has no such split, so the name does not collide there.)

**There is no `public/`, and the kernel has no notion of one.** It existed for the FPM
document-root model — nginx needs a directory to aim at, and `index.php` must live
inside it so sources are not web-reachable. Swoole has no document root: the server
process decides what it serves. FPM is moving to a separate `winter-fpm` project, which
will own its own document root.

`Kernel::init()` therefore takes `pathResource`, `pathStorage*` and no `pathPublic`.

### Static files — Swoole serves them, the framework does not

Opt-in, declared where the rest of the web config lives:

```php
public function configureServer(ServerSettings $server, ApplicationArguments $args): void
{
    $server->port(8000)
           ->staticPath('resources/static');   // resources/static/app.css → /app.css
}
```

`staticPath()` resolves a relative path against `Kernel::$pathRoot`, **throws
`ApplicationConfigException` if the directory is missing** (a typo would otherwise be
silent 404s at runtime), and sets Swoole's `document_root` + `enable_static_handler`.
Say nothing and no file is ever served — which is what an API-only service wants.

Three consequences worth knowing:

- **The directory is the URL root.** Swoole appends the whole request path to it, so
  the layout on disk mirrors the layout in URLs. Point it at a directory holding assets
  and nothing else: everything under it becomes downloadable. (Pointing it at
  `resources` would expose `resources/views` — executable PHP.)
- **Static responses never reach PHP**, so middleware, CORS and request logging do not
  apply to them. (No regression: the old PHP implementation also served before the
  CORS block.)
- **One directory only.** `document_root` is a single value — a second `staticPath()`
  call cannot mount a plugin's assets from another directory. If that is ever needed,
  the answer is collecting them into the one root (a `call assets link`-style step),
  not a second root.

Swoole checks the filesystem on each request to decide whether it is a static one.
Narrowing that to certain prefixes is a tuning knob rather than part of the API —
`->set('static_handler_locations', ['/assets'])` when a profile says it matters.

### The framework ships no assets

Its pages are self-contained: the error page inlines its `<svg>` mark rather than
linking one. That is deliberate — the error page is what a visitor sees when the
application is already failing, so it must not depend on the application being
configured correctly. **Do not reintroduce a `/static/...` URL into kernel output**:
static serving is opt-in, so a linked asset simply 404s in a project that never
enabled it.

### Do not reimplement static serving in PHP — it was removed for cause

`Router` used to do it (`static()`, `$publicDir`, a branch in `handle()`,
`serveStaticFile()`). All of it is gone. Verified against a live Swoole server before
removing:

- **Path traversal.** `$file = $this->publicDir . $path` joined the *raw* `request_uri`;
  Swoole does not canonicalise it. `GET /../../../etc/passwd` returned the file —
  arbitrary read, bounded only by the worker's permissions.
- **Memory.** `serveStaticFile()` used `file_get_contents()`, so a 50 MB download meant
  +50 MB RSS per concurrent request. Swoole streams instead.
- **Cost on every request.** `pathPublic` was always derived and always wired, so every
  project paid an `is_file()` syscall on every GET — including those with no static
  files at all.
- No `Range` (no seeking/resume), no `ETag`/`304`, `mime_content_type()` per request.

Swoole's handler covers all of it in C, including refusing to escape `document_root`
(verified: the traversal requests above return 404 there).

Design history: this section is the record; the working notes it came from are gone.


---

## 15. `WinterApplication` — the starter

### What replaced what

There is no god bootstrap class any more. The old `BaseBoot`/`Application` exposed seven
hooks (`configure`, `providers`, `channels`, `httpCors`, `health`, `plugins`,
`swooleConfig`) that every project had to override; all of them are gone except
`configure()`.

```php
#[EnableWeb]
#[EnableActuator]
final class Application extends WinterApplication
{
    public static function main(array $argv): never { parent::run($argv); }
}
```

Two rules carry the design:

- **The manifest is declarative.** `#[Enable*]` on the application class says what the
  application *is made of*. Each attribute maps to one `Component`
  (`EnableWeb` → http, `EnableProcess`/`EnableDaemon` → workers, `EnableScheduler` →
  scheduler), except `EnableAsync`, which only toggles `#[Async]` proxying during boot.
- **Configuration is discovered, not hooked.** `#[Configuration]`/`#[Bean]`,
  `WebConfigurer`, `LoggingConfigurer`, `HealthContributor`, `#[Import]` — all found by
  the single scan pass. Adding configuration is adding a class.

An empty manifest is an error (`ApplicationConfigException`), not a silently idle
application.

### Entries

| Entry | Who calls it |
|---|---|
| `main($argv)` → `run($argv)` | the project's `call` |
| `serve()` | `run` when the verb is `run` |
| `executor($argv)` | **only** `vendor/bin/wKernelRunner` — the child side of `dispatch()` (see §12) |
| `discoverAppClass()` | that runner, to find the app class after requiring `bootstrap.php` |

`configure()` is the one hook that survived, and it must stay a method: it runs
`Kernel::init()`, which decides *where the scan looks*, so it cannot itself be a
discovered class. Its default derives the project root from the application class's own
file.

### Boot order (`bootstrap()`)

```
1. $appClass = static::class
2. configure()            ← Kernel::init: paths, .env, logging
3. Container::init()
4. ONE Scanner pass       ← DICollector + ConfigurationCollector + WebConfigurer
                            + LoggingConfigurer + HealthContributor (+ AsyncCollector
                            only when #[EnableAsync] is present)
5. contextual LoggerInterface binding
6. applyLogging → applyCors → applyActuator → applyImports
```

One pass, not one per concern — adding a collector means adding it to that pass, never a
second `Scanner::run()`.

### Gotchas

- **`main()` must not declare a default for `$argv`.** It once did (`array $argv = []`)
  and broke every subclass that overrode `main(array $args)`; PHP rejects the narrower
  signature. Caught only by a smoke run.
- **`#[EnableAsync]` gates the collector itself**, not just a flag: without it the
  `AsyncCollector` is never created, so `#[Async]` methods run synchronously. It is
  collected last, after `DICollector` rebinds a class to itself.
- The banner is suppressed by `--no-banner`, `WINTER_BANNER=off`, or a non-TTY stdout.
