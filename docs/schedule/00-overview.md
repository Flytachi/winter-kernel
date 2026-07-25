# Winter Schedule — Overview

Some work is neither a request nor a long-lived consumer: it is a small job that
has to run *again and again on a clock*. Flush a cache every thirty seconds.
Poll a payment gateway for settled transactions every two minutes. Roll yesterday's
metrics into a report five seconds after the last roll finished. There is nothing
to consume from a queue and nothing to keep a connection open for — there is only
a method and a cadence.

**Schedule** is the layer for that. You annotate an ordinary method with
`#[Scheduled]`, name a cadence, and a single system process — the **Scheduler** —
discovers every such method across the project and fires it on time. It is the
declarative model Spring popularised as `@Scheduled`, expressed with a PHP
attribute and built directly on the [Process](../process/00-overview.md) layer.

```php
final class ReportService
{
    #[Autowired] private MetricStore $metrics;

    // 5 seconds after each run finishes, run again
    #[Scheduled(fixedDelay: 5.0)]
    public function roll(): void
    {
        // ... aggregate and persist ...
    }

    // start every 2 seconds, first run 10 seconds after boot
    #[Scheduled(fixedRate: 2.0, initialDelay: 10.0)]
    public function poll(): void
    {
        // ... check the gateway ...
    }
}
```

You never wire the two together. The method carries the *policy* (when to run);
the Scheduler supplies the *mechanism* (discovery, timing, dispatch, isolation).

## What the Scheduler gives you

- **Discovery.** On boot it scans the project (and plugins) for every
  `#[Scheduled]` method — the same scan the router uses for its mappings.
- **Timing in seconds.** Cadences are floats, in seconds, like the rest of the
  kernel (`sleep()`, `grace`) — `fixedDelay: 5.0`, not milliseconds.
- **A concurrency pool.** Each due task is dispatched with the Process
  `spawn()` primitive — a coroutine under Swoole — so a slow task never delays
  another's clock. A task **never overlaps itself**: an in-flight run holds the
  next fire until it finishes.
- **Failure isolation.** A task body is the developer's code and may be wrong —
  the Scheduler is built so one bad task cannot take down the schedule. A task
  that **throws** is caught and logged; the next fire still happens on time. A
  **slow** task runs in its own `spawn`, so it never delays another task's clock,
  and it **never overlaps itself** — a run that outlasts its period simply holds
  its own next fire, it does not pile up. See
  [Usage — robustness](01-usage.md#robustness-against-a-buggy-task-body) for the
  one caveat (a CPU-bound task that never yields under the Swoole runtime).
- **One per host.** The Scheduler inherits the Process singleton lock (a
  crash-safe `flock`), so a second start refuses — a task is never fired twice by
  two schedulers on the same host.
- **Live reload.** `SIGHUP` re-scans the annotated methods without a restart.

## Triggers

A `#[Scheduled]` method declares **exactly one** trigger:

| Trigger | Meaning |
|---|---|
| `fixedDelay: N` | Wait N seconds between the **end** of one run and the **start** of the next. The gap is immune to a run's own duration. |
| `fixedRate: N` | Start every N seconds, measured from the previous **start**. If a run outlasts the period, the next fires once as soon as it frees — missed ticks are dropped, never replayed as a burst. |
| `cron: '…'` | A clock-aligned five-field cron expression (or a macro), for time-of-day work: `0 2 * * *` every night at 02:00, `0 8 * * 1-5` at 08:00 on weekdays, `* * * * *` every minute on the minute, `0 * * * *` hourly on the hour. See [Usage](01-usage.md#cron--clock-aligned). |
| `initialDelay: N` | Seconds to wait before the very first run — with a period trigger only (a cron is already clock-aligned). |

The period triggers (`fixedDelay` / `fixedRate`) answer "how long between runs";
`cron` answers "at which wall-clock times". A single project freely mixes both —
a `fixedRate: 5.0` health poll and a `cron: '0 3 * * *'` nightly cleanup live side
by side, each on its own schedule.

Anything invalid — no trigger, more than one, a non-positive period, a malformed
cron, `initialDelay` with cron, a static method, a method that needs arguments, or
a non-instantiable class — is rejected at discovery with a `ScheduleConfigException`,
so a misconfiguration fails loudly at start rather than silently never firing.

## Running it

The Scheduler is one fixed runtime, not a class you write, so it is driven by its
own command rather than by naming a class:

```
call schedule list          # every #[Scheduled] task and its cadence (no run needed)
call schedule start         # run in the foreground
call schedule start -d      # run detached in the background
call schedule status        # run state + task count
call schedule stop          # graceful SIGTERM
```

See [Usage](01-usage.md) for the attribute in depth, the execution model, and the
fork-safety note under the non-Swoole runtime.
