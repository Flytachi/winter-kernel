# Supervision under `call run`

`call run` is always a supervisor: every background component — Process, Scheduler,
WebSocket, Daemon, outside program — runs in a process of its own, and the
application decides what happens when one cannot run. The user-facing rules are on the
web page *Application components → Supervision*; this page is how they are built.

## Shape

```
php call run                     (PID 1: the application — claims it, supervises)
├── winter-http: manager → Worker N   (the HTTP server, with #[EnableHttp])
├── winter-process: …
├── winter-daemon: … master → workers
└── php scripts/x.php / node …        (outside programs, exec'd)
```

- `App\Runtime\ApplicationRuntime::claim()` — in `serve()`, before anything binds or
  forks: the application lock (`runnable/app.<App>.lock`, a `LockFile`) plus the
  application record (PID, start time, host, components). A second `call run` of the same
  application on the same storage is refused. Liveness is the lock, as for processes.
- `App\Supervision\Supervisor` runs in PID 1 in both shapes — with and without the HTTP
  server — and its result is the exit code (1 when a critical component failed).
- The HTTP server is `ComponentKind::Http`: PID 1 prepares what is shared (settings, the
  route scan, the dev watcher — `App\HttpTier`), the component's process creates and
  starts the Swoole server (`WinterApplication::runHttp()`), ready on `onStart`. PID 1 is
  never the Swoole master: Swoole runs threads there, and a threaded process must not
  fork the rest. SIGHUP/SIGUSR1/SIGUSR2 to PID 1 are forwarded to it (worker reload).
- `call run dev`: the watcher in the HTTP process stops the server and sends `L`; the
  supervisor stops everything and PID 1 re-execs itself, so every component runs the new
  code.
- Each component runs in a process group of its own; when its main process exits, the
  rest of the group is SIGKILLed — a Swoole worker of a killed server would otherwise keep
  the port, a daemon's workers would run on unwatched.

## Phases and the channel

Each start opens a socket pair; the child keeps one end in `ComponentChannel`:

| Message | Sent by | Means |
|---|---|---|
| `R` ready | Process before `run()` (`readyOnRun()`), WebSocket before `$server->start()`, Daemon once every slot is RUNNING | starting → running |
| `S` stopped | Process/Daemon ending after SIGTERM/SIGINT | a stop on request: not restarted, recorded `TERMINATED` |
| `F` failed | Daemon that gave up (maxRestarts) or threw | a failure, never retried |
| `H` held | the child, on `ProcessAlreadyRunningException` | an outsider (or `ensureNotRunning()`) holds it: wait, retry every second |
| `L` reload | the HTTP process under `call run dev`, after a watched change | restart the whole application in place |

Decisions in `Supervisor::exited()`: shutdown → stopped; restart requested → start at
once; start timed out → failure to start; `H` → held; `S` → stopped; `F` → fail; clean
exit of a Process or Command → completed; anything else → crash. A crash before the first
readiness of this run fails at once; after it, `crashed()` counts restarts in a sliding
`Component::RETRY_WINDOW` (1800 s) and backs off 1, 2, 4… s up to `retries` (0 for a daemon
and an outside program). `fail()` stops everything with exit 1 when `critical`, else marks
the unit `Failed` and writes a `FAILED` record.

Outside programs (`ComponentKind::Command`): the child closes the channels, `chdir`s to the
project root and `pcntl_exec`s — through `/usr/bin/env` for a bare name, with
`getenv()` + `$_ENV`. Ready at fork; on shutdown SIGTERM, SIGKILL after `grace`.

## Control

- Records the supervisor writes for a component that is down (`TERMINATED`, `FAILED`)
  name the supervisor's PID and the run (`ManagedBy`); `Process::status()` keeps them only
  while that run is alive. `forgetRecord()` removes only those.
- `Process::stop()` = SIGTERM → `S` → stays down. `dispatch()` of a component of the live
  application (`ApplicationRuntime::managing()`, by `WinterApplication::getAppClass()`)
  writes a `ComponentControl::START` request; `relaunch()` writes `RESTART`. Requests name
  the run and expire after 60 s; the supervisor reads them every 0.5 s. `start()` of such a
  component throws `ProcessManagedException`.
- A record with another `host` (a container's, read through a shared storage) is returned
  as is and never signalled; `ensureNotRunning()` refuses with a pointer to that
  environment.

## Traps met on the way

- The supervisor never touches the Swoole hook flags. Left unset, `Coroutine\run()` switches
  all hooks on inside a component's coroutines; set to 0 (even explicitly), it does not, and
  `spawn()` runs its I/O one task at a time. The HTTP process switches them on itself
  (`enableCoroutine(SWOOLE_HOOK_ALL)`) before creating the server. Should PID 1 ever run
  with hooks on, `listen()` polls instead of `stream_select()`, which is fatal outside a
  coroutine then.
- In a process forked from one Swoole created, `exit()` only throws `ExitException`: a child
  leaves with `Termination::leave()`, or it would run on into the supervisor's loop.
- A daemon's workers inherit the daemon's channel: `runWorker()` detaches it first.
- `Daemon::restart()` is the workers' restart policy: the control method is `relaunch()`.
- Tests: `pgrep -f "call run --port=N" | head -1` finds the shell wrapper, not the master.

Tests: `tests/Process/Integration/{ApplicationRuntime,Supervisor,ComponentControl}IntegrationTest.php`.
