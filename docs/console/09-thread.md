# `call thread` (`call th`) — run Dispatchable tasks & manage daemons

Runs a `Dispatchable` class — a queue job, long-lived process, websocket
handler, or daemon — in the foreground (blocking) or detached as a
background process. Daemons get a full lifecycle: start, stop, status and
a live overview.

Alias: **`th`**.

---

## Synopsis

```
call thread list
call thread daemons
call thread <dot.notation.Class> [-d]
call thread <dot.notation.Daemon> [start | stop | status [-v]] [-d]
```

| Command                         | Purpose                                                        |
|---------------------------------|----------------------------------------------------------------|
| `list`                          | List every non-abstract `Dispatchable` class, tagged by kind   |
| `daemons`                       | List daemons with live state, fork count and uptime            |
| `<Class>`                       | Foreground — `Class::start()` (blocking)                       |
| `<Class> -d`                    | Background — `Class::dispatch()` (returns child PID)           |
| `<Daemon>`                      | Toggle: stop if running, else start in foreground              |
| `<Daemon> -d`                   | Toggle: stop if running, else start in background              |
| `<Daemon> start`                | Start the daemon in background (`::dispatch()`)                |
| `<Daemon> stop`                 | Stop the running daemon (`::stop()`)                           |
| `<Daemon> status`               | Show daemon state, PID, uptime, fork count                     |
| `<Daemon> status -v`            | Detailed: process resources + child fork list                 |

There is no `run` sub-command — the class is passed directly. The runner
requires the `pcntl` extension for async signal handling; without it,
running a thread exits with a warning.

Class resolution is dot-notation: `ucfirst` each segment, `.` → `/` → `\`
(e.g. `main.threads.ExampleJob` → `Main\Threads\ExampleJob`). A missing
class or one that doesn't implement `Dispatchable` produces a clear
warning instead of a stack trace.

---

## `call thread list`

Lists every non-abstract class implementing `Dispatchable`, discovered
across the project and registered plugins. The badge tells you which kind
it is:

```bash
call thread list
```

```
 | [============ Thread ============]
 | [ Available Threads ]
 |   Main.TestDaemon ............... [Daemon]
 |   Main.S1Job ..................... [Job]
 |   Main.TestJob ................... [Job]
 |   Main.TestProcess ............... [Process]
 | [ Available Threads ]
```

`Daemon` is highlighted in a distinct colour; `Job` / `Process` and any
other `Dispatchable` (`Dispatchable` badge) share the default colour.

---

## `call thread daemons`

A focused view of `ThreadDaemon` subclasses with their **live** state:

```bash
call thread daemons
```

```
 | [ Available Daemons ]
 |   Main.TestDaemon ............... [● RUNNING] [forks:2] 2m 14s
 |   Main.Cleanup .................. [○ STOPPED]
 | [ Available Daemons ]
```

For a running daemon the row shows the active fork count and uptime;
stopped daemons show just the state.

---

## Running a thread (Job / Process)

Resolves the class, confirms it implements `Dispatchable`, then:

| Mode              | Call                 | Behavior                                          |
|-------------------|----------------------|---------------------------------------------------|
| Foreground        | `$class::start()`    | Blocks until the task finishes — output & signals on the current TTY |
| Background (`-d`) | `$class::dispatch()` | Forks a detached child; prints the new PID        |

```bash
call thread main.threads.ExampleJob          # foreground
call thread main.threads.ExampleJob -d        # detach, prints PID
```

```
 | [✓] Dispatched: Main\Threads\ExampleJob
 |   PID         48213
```

---

## Managing a daemon

When the resolved class is a `ThreadDaemon`, `thread` switches to
lifecycle mode.

### Toggle (no sub-command)

`call thread <Daemon>` is a smart toggle:

- **running** → stops it (prints the PID it stopped);
- **stopped** → starts it in the **foreground** (blocking), or in the
  **background** with `-d`.

```bash
call thread main.threads.Cleanup        # stopped → foreground start; running → stop
call thread main.threads.Cleanup -d     # stopped → background start;  running → stop
```

`-d` is only read in this toggle (no-command) mode — start/stop/status
ignore it.

### `start` / `stop`

```bash
call thread main.threads.Cleanup start   # always background dispatch
call thread main.threads.Cleanup stop
```

```
 | [✓] Started: Main\Threads\Cleanup
 |   PID         48213
```

```
 | [✓] Stopped: Main\Threads\Cleanup
 |   PID         48213
```

Starting an already-running daemon, or stopping one that isn't running,
prints a clear warning instead of an error.

### `status` and `status -v`

`status` is the lightweight view; add `-v` for resource stats and the
child-fork list.

```bash
call thread main.threads.Cleanup status
call thread main.threads.Cleanup status -v
```

```
 | [ Daemon Status ]
 |   Main.Threads.Cleanup ............... [● RUNNING]
 | - - - - - - - - - - - - - - - - - - - -
 |   PID          48213
 |   Condition    ACTIVE
 |   Started      2026-06-29 09:16:18 +00:00
 |   Uptime       2m 14s
 |   Stream RPS   4
 |   Forks        2
 | [ Daemon Status ]
```

With `-v`, two more sections are appended:

```
 | - - - - - - - - - - - - - - - - - - - -
 | [ Resources ]
 |   User         www-data
 |   PPID         1
 |   CPU          0.1 %
 |   Memory       0.4 % (24.5 MB)
 |   Elapsed      02:14
 |   Command      php .../wKernelExecutor ...
 | - - - - - - - - - - - - - - - - - - - -
 | [ Forks (2) ]
 |   #48261   ACTIVE      2m 10s  cpu 0.1%  rss 8.2 MB
 |   #48262   WAITING     2m 09s  cpu 0.0%  rss 7.8 MB
 | [ Daemon Status ]
```

A stopped daemon prints `[○ STOPPED]` and a one-line note.

---

## Foreground vs background

| Use foreground when…                            | Use `-d` / background when…                 |
|-------------------------------------------------|---------------------------------------------|
| You want output in your terminal                | You want to detach and walk away            |
| It's a one-off job                              | Starting a long-running daemon / worker     |
| You're inside a systemd/Docker entrypoint       | You need the PID to track or signal later   |

For production daemons, prefer a real supervisor (systemd, supervisord,
Docker `restart: always`) pointing at the daemon in **foreground** — let
the supervisor own the process lifecycle. The `start`/`stop`/`-d` actions
are for ad-hoc management from a shell.

---

## Examples

```bash
call thread list
call thread daemons
call thread main.threads.ExampleJob
call thread main.threads.ExampleJob -d
call thread main.threads.Cleanup            # toggle (foreground)
call thread main.threads.Cleanup -d         # toggle (background)
call thread main.threads.Cleanup start
call thread main.threads.Cleanup status
call thread main.threads.Cleanup status -v
call thread main.threads.Cleanup stop
```

---

## Notes

- `pcntl_async_signals(true)` is enabled before running a thread so Ctrl-C
  and `kill` behave predictably in foreground mode.
- `list`, `daemons`, and the `Complete` suggestion engine share the same
  unified class discovery (`ClassScanner` + collectors) — newly added
  classes appear immediately after autoload regenerates.
- Tab-completion is context-aware: after a daemon class it suggests
  `start` / `stop` / `status` / `-d`; after `status` it suggests `-v`;
  after a Job/Process it suggests `-d`.

---

## Source

- `console/Command/Thread.php`
- Discovery: `src/Core/ClassScanner.php`, `src/Collector/{ImplementorCollector,SubclassCollector}.php`
- Contract: `src/Process/Core/Dispatchable.php`
- Stereotypes that implement it: `src/Stereotype/{Job,Daemon,Process,WebSocket}.php`
- Engine: `src/Process/Thread{Job,Daemon,Process}.php`

## See also

- [02-make.md](02-make.md) — scaffold a `Job` / `Daemon` / `Process` / `WebSocket`
- [05-script.md](05-script.md) — the analogous `Cmd`/`CmdCustom` runner
