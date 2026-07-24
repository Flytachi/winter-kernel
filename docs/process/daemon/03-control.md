# Controlling a Daemon

A daemon is started, observed and stopped exactly like a [`Process`](../03-control.md) —
the same four verbs — but everything reports the *fleet*: the master plus its
workers. This page covers the control surface, the CLI, the stop sequence, and the
per-worker view.

## The control surface

```php
Emails::start();              // supervise in the foreground (blocks)
$pid = Emails::dispatch();    // supervise detached in the background → master PID
$info = Emails::status();     // ?DaemonStatus  (null when not running)
Emails::stop();               // graceful stop — drains the whole fleet
```

`start()` and `dispatch()` are refused if the daemon is already running: a daemon
is a **singleton per class**, guarded by a crash-safe `flock` the master holds for
its lifetime. `status()` returns a `DaemonStatus` — a `ProcessStatus` plus the
fleet: `restarts` and a `workers[]` snapshot. It is a pure read that never mutates
the record, and it works across users (liveness via `posix_getpgid`).

## The CLI — `call daemon`

Daemons have their own command, separate from `call process` (bare processes):

```
call daemon list                          # every daemon, with live state + worker count
call daemon main.daemon.Emails            # supervise in the foreground
call daemon main.daemon.Emails start -d   # supervise detached in the background
call daemon main.daemon.Emails status     # status + the per-worker fleet table
call daemon main.daemon.Emails status -v  # also the master's resource usage
call daemon main.daemon.Emails stop       # graceful stop
call dmn    main.daemon.Emails stop       # 'dmn' is the alias
```

`call daemon list` shows only daemons; bare processes live under `call process`.

### The per-worker view

`status` renders the fleet the supervisor sees — one row per slot, so you can tell
at a glance why the fleet is the size it is:

```
[ Workers (3) ]
  SLOT   PID      STATE       ACT    UPTIME    RESTARTS
  #0     41201    running     busy   12m03s    0
  #1     41202    running     idle   12m03s    0
  #2     41230    retiring    busy   45s       1
```

`STATE` is the slot's authoritative lifecycle (`starting` / `running` / `retiring`
/ `killing` / `restarting` / `retired`); `ACT` is the worker's own `IDLE` / `BUSY`
heartbeat, about a second behind. A `retiring` row explains why the live process
count can exceed the active fleet — that worker is draining on its way out; a
`retired` row is a death the restart policy declined to replace (a `NEVER` worker,
or a clean exit).

## Background dispatch

`dispatch()` (CLI `-d`) sends the **whole daemon** to the background via the Thread
launcher: the master detaches, re-parents to init, and keeps running after the
shell closes. The call returns the master PID, and — because the detached
double-fork reports an intermediate PID — the CLI briefly polls the store for the
real master PID before printing it. Once the master is up, it forks its own workers
with `pcntl` (see [Workers](01-workers.md#how-a-worker-is-created-fork-not-thread)).

```
CLI: Emails::dispatch()  ──Thread──►  master (PPID=1) in the background
                                       │  pcntl_fork ×N
                                       ├──► worker#1
                                       ├──► worker#2
                                       └──► worker#3
```

## Stopping the fleet

`stop()` sends SIGTERM to the master, and the master runs a single, ordered stop
sequence — a full stop is simply "retire the whole fleet at once", reusing the same
graceful drain the autoscaler uses:

1. **Freeze the autoscaler first.** The daemon enters `STOPPING`; reconcile no
   longer spawns or restarts anything. This is what stops the restart policy from
   resurrecting workers as they exit.
2. **Drain every worker in parallel.** SIGTERM goes to all workers at once; each
   drains to idle on its own body (finishing its current unit, refusing new work),
   then exits.
3. **Force stragglers.** A worker that outlives its drain deadline is SIGKILLed.
   The deadline is the daemon's `$grace` (`0` = wait forever). A **second** stop
   signal collapses every deadline to *now* — the operator's "stop now", like a
   second `Ctrl+C`.
4. **Tear down.** Once the fleet is empty the master runs `onShutdown()`, removes
   its store record, releases the `flock`, and exits `TERMINATED`.

No worker is orphaned (the master waits for the fleet) and none is restarted (the
autoscaler is frozen). Set `$grace > 0` for a bounded shutdown; leave it `0` to
wait indefinitely for a clean drain.

## Process titles

Every process in the tree shares the `winter-daemon:` prefix, so the whole family
is visible — and killable — together:

```
winter-daemon: Emails master
winter-daemon: Emails worker#1
winter-daemon: Emails worker#2
```

```bash
pkill -f 'winter-daemon: Emails'   # the entire fleet
```

The name is `$processTitle` if set, otherwise the daemon's short class name.

## Lifecycle states

The master's `DaemonStatus.state` is one of:

| State | Meaning |
|---|---|
| `RUNNING` | supervising the fleet |
| `STOPPING` | draining the fleet on a stop request |
| `TERMINATED` | the fleet drained and the master exited |
| `FAILED` | `maxRestarts` was exceeded; the daemon gave up and stopped |

See [Autoscaling & restart](02-autoscaling.md) for what drives `FAILED`, and
[Workers](01-workers.md) for the body and its fork-safety.
