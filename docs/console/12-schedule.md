# `call schedule` — run the scheduler and list `#[Scheduled]` tasks

Drives the **Scheduler** — the single process that fires every method annotated
with `#[Scheduled]` on its trigger. Unlike `call process` / `call daemon` there is
no class to name: the scheduler is one fixed runtime, and its "schedule" is the set
of annotated methods it discovers across the project.

Alias: **`sch`**.

---

## Synopsis

```
call schedule list          # list every #[Scheduled] task and its cadence (default)
call schedule start          # run the scheduler in the foreground
call schedule start -d       # run it detached in the background
call schedule status         # run state + task count
call schedule status -v      # also the master's resource usage
call schedule stop           # graceful stop (SIGTERM)
```

Running `call schedule` with no action is the same as `call schedule list`.

---

## Actions

### `list`

Runs the discovery scan (`ClassScanner` + `ScheduledCollector`) and prints every
task and its trigger. It is a **static scan** — nothing has to be running, and it
is the fastest way to confirm an annotation was picked up (or why it was rejected:
a misconfigured `#[Scheduled]` fails the scan with a `ScheduleConfigException`).

```
 | [ Scheduled Tasks ]
   TASK                                     TRIGGER
   App\ReportService::nightly               cron 0 2 * * *
   App\PollService::poll                    fixedRate 30s
 | [i] 2 task(s) defined.
```

### `start`

Launches the scheduler. Foreground by default (blocks the terminal; `Ctrl-C`
stops it). With `-d` it is dispatched **detached** into the background and the
command returns after briefly polling for the started PID.

| Flag | Effect |
|------|--------|
| `-d` | Start detached in the background instead of the foreground |

A second `start` while one is already running is refused — the scheduler holds a
per-class singleton lock (a crash-safe `flock`), so there is never more than one
per host.

### `stop`

Sends a graceful `SIGTERM` to the running scheduler. In-flight task runs drain,
then the process exits and removes its status record. Prints a warning if nothing
is running.

### `status`

Reports the scheduler's run state, PID, activity, uptime and the number of
discovered tasks. `-v` additionally attaches the master process's live resource
usage (CPU / memory via `ps`).

| Flag | Effect |
|------|--------|
| `-v` | Attach the master's resource usage |

```
 | [ Scheduler Status ]
   PID          48213
   State        RUNNING
   Activity     IDLE
   Started      2026-07-26 02:00:00 +00:00
   Uptime       6h 12m
   Tasks        2
```

When nothing is running, `status` prints `○ STOPPED`.

---

## Runtime notes

- **One per host.** The singleton lock means `start` / `dispatch` refuse a second
  instance; a task is never fired twice by two schedulers on the same box.
- **`list` needs no running process** — it boots the container and scans, exactly
  as the scheduler does on start, so it surfaces configuration errors early.
- **Under FPM there is no long-lived scheduler.** Like `call daemon`, this is a
  CLI-driven long-running process; in production run it under a supervisor
  (systemd / supervisor / a Kubernetes deployment) with `start` in the foreground,
  or `start -d` for a quick detached run.

---

## Examples

```bash
call schedule list             # what got discovered?
call sch list                  # same, via the alias
call schedule start            # run in the foreground (Ctrl-C to stop)
call schedule start -d         # run detached; note the PID it prints
call schedule status -v        # is it alive, and how much is it using?
call schedule stop             # ask it to stop gracefully
```

---

## Source

- `console/Command/Schedule.php` — the command
- `src/Schedule/Scheduler.php` — the runtime it starts
- `src/Schedule/ScheduledCollector.php` — the discovery `list` runs

## See also

- [`../schedule/00-overview.md`](../schedule/00-overview.md) — what the Scheduler is and how triggers work
- [`../schedule/01-usage.md`](../schedule/01-usage.md) — writing `#[Scheduled]` methods, recipes, production
- [`../process/03-control.md`](../process/03-control.md) — the `start` / `stop` / `status` model the scheduler inherits from Process
