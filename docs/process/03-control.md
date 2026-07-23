# Control — start, stop, status

A process exists to be operated. Someone — a deploy script, an operator, a
supervisor, an admin dashboard — needs to launch it into the background, ask
whether it is alive and what it is doing, and stop it cleanly when the time comes.
That operational surface is four static methods on every process. The
`call process` command is a thin, ergonomic wrapper over those four; the web layer
is whatever you build on the same four. Nothing else is privileged — the CLI has
no special access the rest of your application lacks.

---

## The four methods

```php
EmailDispatchWorker::start();                 // run in this process, in the foreground
$pid = EmailDispatchWorker::dispatch();       // launch detached in the background, return its PID
$status = EmailDispatchWorker::status(true);  // its status (with live resource usage), or null
EmailDispatchWorker::stop();                  // ask it to stop gracefully
```

**`start()`** runs the body in the current process and blocks the caller until it
returns. While it runs it registers itself in the store, so another terminal can
still find it with `status()` and `stop()`. This is what you use in development and
for one-shot tasks you want to watch.

**`dispatch()`** launches the process detached — double-forked and `setsid`-ed so
it has no controlling terminal — and returns immediately with its PID. A detached
process survives the shell that started it and is what you use for a real service.

**`status()`** reads the process's record from the store: its PID, lifecycle
state, activity, uptime and concurrency. Pass `true` to also attach a live
`ResourceUsage` snapshot. It returns `null` when the process is not running, and it
is safe to call from anywhere — a controller, a health check, a scheduled audit.

**`stop()`** sends `SIGTERM`, beginning the graceful stop sequence from
[01-lifecycle.md](01-lifecycle.md). `SIGTERM` is the deliberate choice here: it is
the universal "terminate, and you may clean up" signal that `kill`, systemd, Docker
and Kubernetes all send by default. `SIGINT` is left to mean an interactive Ctrl-C.

Because these are ordinary static methods, the web layer is not a separate feature
— it is a few lines that call them. A minimal operations endpoint looks like this:

```php
class WorkersController extends Controller
{
    #[GetMapping('/ops/workers/email')]
    public function emailStatus(): ResponseEntity
    {
        $status = EmailDispatchWorker::status(usage: true);

        if ($status === null) {
            return ResponseEntity::ok(['running' => false]);
        }

        return ResponseEntity::ok([
            'running'   => true,
            'pid'       => $status->pid,
            'activity'  => $status->activity->name,   // BUSY | IDLE
            'uptime'    => time() - $status->startedAt,
            'memory_mb' => round($status->usage?->rssMb() ?? 0, 1),
        ]);
    }

    #[PostMapping('/ops/workers/email/stop')]
    public function stopEmail(): ResponseEntity
    {
        return ResponseEntity::ok(['stopped' => EmailDispatchWorker::stop()]);
    }
}
```

---

## One instance per class

A process is a **singleton per class**: one class means one running instance. The
whole control model assumes it — the status record is keyed by the class name, and
`status()` and `stop()` address a process *by its class*, which only has an
unambiguous answer when there is exactly one. Starting a second instance of the
same class would have both write to the same record, so `status()` would see one
while the other ran orphaned and unreachable.

`start()` and `dispatch()` therefore refuse a second instance and throw
`ProcessAlreadyRunningException`:

```php
try {
    EmailDispatchWorker::dispatch();
} catch (ProcessAlreadyRunningException $e) {
    // one is already running — $e carries its PID and start time
}
```

The guard has two layers, so it holds even under a race. `start()` / `dispatch()`
first check the store and refuse immediately if a live instance is recorded. The
launched worker then takes an exclusive `flock` keyed by its class before it does
anything; if two launches slip past the first check at the same instant, only one
wins the lock and the other exits. Because a `flock` is released by the operating
system the moment the process dies, it is never left stale — a crashed or
`kill -9`-ed process frees it instantly, and the next launch succeeds with no
manual cleanup, which a PID file could not guarantee.

To run **several workers of the same logic**, this is not the mechanism — launching
the same class repeatedly is precisely what is forbidden. Use a `Daemon`, which
supervises a configurable number of identical worker processes, or define distinct
classes for distinct roles. Multiplicity lives in the supervisor; a bare process
stays a single, unambiguous, named instance.

---

## The command line: `call process`

`call process` (aliased to `call proc`) drives the four methods and renders live
state, with tab-completion wired for command names, discovered process classes,
and flags.

```
call process list                             # every process, with live state
call process main.EmailDispatchWorker         # start in the foreground (blocks the shell)
call process main.EmailDispatchWorker start -d   # start detached in the background
call process main.EmailDispatchWorker status     # the status card
call process main.EmailDispatchWorker status -v  # + resource usage (and, for a daemon, its workers)
call process main.EmailDispatchWorker stop        # graceful stop
```

`list` gives you the fleet at a glance. Each row shows a `[P]` process or `[D]`
daemon tag, its running/stopped state, its live `[BUSY]`/`[idle]` activity, and its
uptime, followed by a one-line summary:

```
 [P] Main.EmailDispatchWorker ......... [● RUNNING] [BUSY] 4h 12m
 [P] Main.ExpiredSessionCleanup ....... [○ STOPPED]
 [D] Main.SmsGatewayBridge ............ [● RUNNING] [idle] [w:4] 2d 1h
 3 defined, 2 running.
```

`status -v` prints the full card, including CPU and resident memory read live from
`ps` at the moment you ask:

```
 Main.EmailDispatchWorker           [Process ● RUNNING]
 ─────────────────────────────────────────────
 PID          58753
 State        RUNNING
 Activity     BUSY
 Started      2026-07-23 07:41:02 +00:00
 Uptime       4h 12m
 Concurrency  25
 ─── Resources ───
 CPU          3.1 %
 Memory       0.4 % (48.2 MB)
```

---

## The status record

While a process runs it owns one small record in the runnable store, keyed by its
class name, holding the PID, lifecycle state, activity, start time and concurrency.
This record is the single source of truth that `status()`, the CLI and any web
endpoint all read; because it is a plain file store, they read it with no locking
or coordination between them.

Two properties keep the record both trustworthy and cheap.

It is **self-healing**. Every `status()` call verifies that the recorded PID still
exists before trusting the record; if the process died without cleaning up — a
forced exit, a crash — the stale record is dropped on that read and the process
correctly reports as stopped. You never see a ghost.

Its writes are **throttled**. The frequently-changing part of the record is the
activity, and a busy worker can flip between `BUSY` and `IDLE` many times a second.
Writing the file on each flip would be pointless disk churn, so activity is
persisted on a roughly one-second heartbeat and only when it has actually changed.
The in-memory value the process acts on is always current; the persisted value the
outside world reads lags by at most about a second, which is exactly the precision
a human or a scale-down decision needs. Live resource usage is never stored at all
— it is read from `ps` on demand.

---

## Resource usage

Passing `usage: true` to `status()` (or `-v` on the CLI) attaches a `ResourceUsage`
snapshot: PID, parent PID, user, CPU percentage, memory percentage, resident set
size and elapsed time, all read from the operating system's `ps`.

```php
$usage = ResourceUsage::ofPid($pid);
$megabytes = $usage?->rssMb();   // resident memory, or null if the process is gone
```

It is designed for **occasional, on-demand** observation — a status card, a
dashboard poll, an alert threshold — and never for a hot path, because each read
forks the `ps` binary. Note the direction: this is a process being *observed from
the outside*, not a process measuring itself. It answers "how much memory is that
worker using right now?" from another process, which is exactly what an operator or
a monitor asks.

---

## Foreground versus detached

The difference between `start()` and `dispatch()` (or `start` and `start -d` on the
CLI) is where the process lives and how long it survives.

| | Foreground — `start` | Detached — `start -d` / `dispatch()` |
|---|---|---|
| Blocks the caller | yes, until the body returns | no — returns a PID immediately |
| Survives the launching shell | no — dies with the terminal | yes — re-parented to init |
| stdout and logs | the terminal | `/dev/null` by default; logging goes to files |
| Typical use | development, one-shot tasks you watch | real long-running services |

A detached launch re-executes through the framework's runner, which boots the
application in the child process so the detached worker has the very same
container, configuration and logger as any other entry point — it is not a
stripped-down environment. One consequence of the double-fork is worth knowing: the
PID the launcher first hands back is an intermediate one, and the real worker
registers its own, final PID in the store as it starts. The CLI resolves this for
you by reading the store, so the PID it prints and the one `status`/`stop` act on
are always the real process; you never target the wrong thing.

---

## Stopping cleanly

`stop()` sends `SIGTERM`, and from there the process runs the sequence detailed in
[01-lifecycle.md](01-lifecycle.md): it stops taking new work, lets the current unit
finish, drains its in-flight `spawn()`ed tasks, runs `onShutdown()`, and exits. How
long that takes is bounded by the worker's `$grace` — `0` means it waits for the
drain for as long as the drain needs. If you cannot wait, a second `stop`, or an
external `kill -9`, forces the process down at once, skipping the drain.

Operating a *fleet* of workers — starting and stopping individual instances by
their activity so that a `BUSY` one is never the one you remove, and scaling the
count up and down with load — is a level above a single process. That is the job of
a `Daemon`, a supervisor that manages a set of Process workers, and it is
documented separately.

---

Back to [00-overview.md](00-overview.md).
