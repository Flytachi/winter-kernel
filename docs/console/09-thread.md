# `call thread` (`call th`) — run Dispatchable tasks

Runs a `Dispatchable` class — a long-lived process, daemon, queue job,
or websocket handler — either in the foreground (blocking) or
detached as a background process.

Alias: **`th`**.

---

## Synopsis

```
call thread <command> [class] [-d]
call th     <command> [class] [-d]
```

| Command   | Purpose                                                          |
|-----------|------------------------------------------------------------------|
| `list`    | List every non-abstract class implementing `Dispatchable`        |
| `run <class>`     | Foreground — `Class::start()` (blocking)                 |
| `run <class> -d`  | Background — `Class::dispatch()` (returns child PID)     |

The runner requires the `pcntl` extension for async signal handling;
if it isn't loaded, `run` exits with a warning.

---

## `call thread list`

Walks all PSR-4 roots under `Kernel::$pathRoot` (and plugins under
`vendor/`) for classes that `implements Dispatchable`. Results are
printed as dot-notation FQCNs.

```bash
call thread list
call th list
```

Sample output:

```
 | [============ Thread ============]
 | [ Available Threads ]
 |   App.Threads.Jobs.SendInvoices ............. [Dispatchable]
 |   App.Threads.Daemons.Cleanup ............... [Dispatchable]
 |   App.Threads.Processes.QueueWorker ......... [Dispatchable]
 | [ Available Threads ]
```

---

## `call thread run <class> [-d]`

Resolves the dot-notation path to a class (`ucfirst` per segment, then
`/` → `\`), confirms it's autoloadable, then either:

| Mode             | Call                | Behavior                                      |
|------------------|---------------------|-----------------------------------------------|
| Foreground       | `$class::start()`   | Blocks until the task finishes — log + signals straight to the current TTY |
| Background (`-d`)| `$class::dispatch()`| Forks a detached child; prints the new PID    |

```bash
call thread run main.threads.ExampleJob          # foreground
call thread run main.threads.ExampleJob -d       # detach
call th run main.threads.daemons.Cleanup -d
```

If the class is missing or doesn't implement `Dispatchable`, you get a
clear warning instead of a stack trace.

---

## Foreground vs background

| Use foreground when…                                  | Use `-d` when…                              |
|-------------------------------------------------------|---------------------------------------------|
| You want logs in your terminal                        | You want to detach and walk away            |
| It's a one-off (`call th run … -d` would race exit)   | Starting a long-running daemon / worker     |
| You're inside a systemd/Docker entrypoint anyway      | You need the PID to track or signal later   |

For production daemons, prefer running them under a real supervisor
(systemd, supervisord, Docker `restart: always`) and pointing it at
`call th run <class>` in **foreground** — let the supervisor own the
process lifecycle. `-d` is for ad-hoc dispatching from a shell.

---

## Examples

```bash
call thread list
call thread run main.threads.ExampleJob
call thread run main.threads.ExampleJob -d
call th run app.threads.Daemons.Cleanup
call th run app.threads.daemons.Cleanup -d
```

---

## Sample output

Foreground:
```
 | [============ Thread ============]
 | [i] Starting: App\Threads\Jobs\ExampleJob
 | ... (task output)
 | [✓] Finished: App\Threads\Jobs\ExampleJob
 | [============ Thread ============]
```

Background (`-d`):
```
 | [✓] Dispatched: App\Threads\Daemons\Cleanup
 |   PID         48213
```

---

## Notes

- `pcntl_async_signals(true)` is enabled before `run` to make Ctrl-C
  and `kill` behave predictably in foreground mode.
- `list` and Complete's `thread run` suggestion engine share the same
  reflection-based discovery — newly added classes show up immediately
  after autoload regenerates.

---

## Source

- `console/Command/Thread.php`
- Contract: `src/Process/Core/Dispatchable.php`
- Stereotypes that implement it: `src/Stereotype/{Job,Daemon,Process,WebSocket}.php`
- Engine: `src/Process/Thread{Job,Daemon,Process}.php`

## See also

- [02-make.md](02-make.md) — scaffold a `Job` / `Daemon` / `Process` / `WebSocket`
- [05-script.md](05-script.md) — the analogous `Cmd`/`CmdCustom` runner
