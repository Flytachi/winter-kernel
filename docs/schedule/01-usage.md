# Winter Schedule — Usage

## Quick start

Three steps, no wiring.

**1. Annotate a method** on any class the container can build:

```php
use Flytachi\Winter\K2\Schedule\Scheduled;
use Psr\Log\LoggerInterface;

class ReportService
{
    #[Autowired] private LoggerInterface $logger;

    #[Scheduled(cron: '0 2 * * *')]        // every night at 02:00
    public function nightly(): void
    {
        $this->logger->info('rolling yesterday’s metrics');
        // ... work ...
    }
}
```

**2. See what was found** (a static scan — nothing has to be running):

```
$ call schedule list
  TASK                                     TRIGGER
  App\ReportService::nightly               cron 0 2 * * *
```

**3. Run the scheduler:**

```
call schedule start        # foreground (Ctrl-C to stop)
call schedule start -d     # background; stop with: call schedule stop
```

That is the whole loop. Everything below is detail on the triggers, the execution
model, and running it in production.

## The attribute

`#[Scheduled]` marks a method to be run on a cadence. It is repeatable, so one
method may carry several triggers.

```php
use Flytachi\Winter\K2\Schedule\Scheduled;

#[Scheduled(fixedDelay: 5.0)]
public function flush(): void { /* ... */ }
```

The method must be:

- **public**, **non-static**, and take **no required arguments** — the Scheduler
  resolves the declaring class from the container on each fire and calls the
  method with no arguments;
- declared on an **instantiable** class (an interface or an abstract class cannot
  be resolved).

Its class needs no marker or base type: any DI-resolvable class works, and its
`#[Autowired]` dependencies are injected as usual, because the instance comes from
the container.

## Choosing a trigger

Declare **exactly one** of `fixedDelay`, `fixedRate`, or `cron`. `initialDelay` is
independent and may accompany either period.

### fixedDelay — steady gap between runs

```php
#[Scheduled(fixedDelay: 5.0)]
public function roll(): void { /* ... */ }
```

The next start is measured from the moment the previous run **finished**. A run
that takes eight seconds is still followed by a full five-second gap. Two runs of
the same task therefore can never overlap. Use it when what matters is breathing
room between runs — most maintenance jobs.

### fixedRate — steady cadence

```php
#[Scheduled(fixedRate: 2.0)]
public function poll(): void { /* ... */ }
```

The next start is measured from the previous **start**, so the cadence is
independent of how long a run takes. If a run outlasts the period, the Scheduler
holds the next fire until the run finishes (a task never overlaps itself) and then
fires it once immediately — the missed ticks are dropped, not replayed as a burst.
Use it when you want a run "about every N seconds" regardless of duration.

### cron — clock-aligned

```php
#[Scheduled(cron: '0 2 * * *')]
public function nightlyCleanup(): void { /* ... */ }
```

Use cron when a run must land at specific wall-clock times rather than at an
interval. The expression has five fields — `minute hour day-of-month month
day-of-week` — each accepting a star, a number, an `a-b` range, a `/step`, and
comma lists. Day-of-week is `0-6` with Sunday `0` (or `7`); when day-of-month and
day-of-week are both restricted the day matches their **union** (the standard
cron rule). Common macros are accepted: `@yearly`, `@monthly`, `@weekly`,
`@daily` (= `@midnight`), `@hourly`.

| You want | Expression |
|---|---|
| Every night at 02:00 | `0 2 * * *` |
| Every morning at 08:00 on weekdays | `0 8 * * 1-5` |
| Every minute, on the minute | `* * * * *` |
| Every hour, on the hour | `0 * * * *` |
| Every 15 minutes | `*/15 * * * *` |
| Twice a day, 06:30 and 18:30 | `30 6,18 * * *` |
| First of every month, midnight | `@monthly` |

Cron fires on the local timezone and is clock-aligned, so it ignores
`initialDelay` (setting both is a configuration error). A malformed expression is
rejected at discovery, not silently ignored.

### initialDelay — delay the first run

```php
#[Scheduled(fixedRate: 2.0, initialDelay: 10.0)]
public function warmThenPoll(): void { /* ... */ }
```

Seconds to wait after boot before the first run — handy for letting dependencies
warm up, or staggering tasks that would otherwise all fire at boot.

## Recipes

Copy-paste starting points for the common cadences.

```php
// every 30 seconds, measured from the start of each run
#[Scheduled(fixedRate: 30.0)]
public function poll(): void { /* ... */ }

// 5 seconds after each run finishes (gap immune to run duration)
#[Scheduled(fixedDelay: 5.0)]
public function drainQueue(): void { /* ... */ }

// warm up, then poll every 10s starting 60s after boot
#[Scheduled(fixedRate: 10.0, initialDelay: 60.0)]
public function refreshCache(): void { /* ... */ }

// every night at 02:00
#[Scheduled(cron: '0 2 * * *')]
public function nightlyCleanup(): void { /* ... */ }

// 08:00 on weekdays
#[Scheduled(cron: '0 8 * * 1-5')]
public function weekdayReport(): void { /* ... */ }

// top of every hour
#[Scheduled(cron: '0 * * * *')]
public function hourlyRollup(): void { /* ... */ }

// every 15 minutes, on the quarter
#[Scheduled(cron: '*/15 * * * *')]
public function everyQuarterHour(): void { /* ... */ }

// two triggers on one method — both fire it
#[Scheduled(cron: '0 9 * * *')]     // 09:00 daily
#[Scheduled(cron: '0 17 * * *')]    // and 17:00 daily
public function twiceADay(): void { /* ... */ }
```

## The execution model

The Scheduler loops: on each pass it fires every task that is due and not already
in flight, then sleeps until the next task is due (bounded, so it stays responsive
to a stop). A fired task is dispatched with the Process `spawn()` primitive:

- **Under Swoole**, `spawn()` is a real coroutine — tasks run concurrently in one
  process, sharing memory, and a slow task never delays another's clock.
- **Without Swoole**, `spawn()` forks a child per run.

Concurrency across *different* tasks is bounded by the Scheduler's `spawn` pool;
the same task never runs concurrently with itself, matching a single-threaded
scheduler's default behaviour.

A task that throws is caught and logged; the schedule is unaffected and the next
fire happens on time.

## Robustness against a buggy task body

A task body is application code and will sometimes be wrong. The Scheduler is
designed so a single bad task cannot derail the schedule or the other tasks:

- **A task that throws** is caught and logged, and the next fire happens on time.
  The exception never reaches the loop.
- **A slow task** runs in its own `spawn`, so it does not delay when any other
  task fires. Its own next fire is held until it finishes (a task never overlaps
  itself), so a run that drifts over its period does not stack up a backlog.
- **A task stuck in a fire-and-forget fork** (the non-Swoole runtime) is isolated
  in its own child process; the Scheduler is unaffected.

The one case the runtime cannot defend against is inherent to cooperative
coroutines: under Swoole, a task that runs a **long CPU-bound loop and never
yields** (no I/O, no `sleep`) holds the single reactor thread, freezing the
Scheduler loop and every other task until it returns. This is not specific to
scheduling — it is how coroutines work. If a task must do heavy, non-yielding CPU
work, either break it into chunks that yield (`\Swoole\Coroutine::sleep(0)` /
periodic I/O), or run the Scheduler under the fork runtime so each run is a
separate process. Blocking *I/O* is not a problem: Swoole's runtime hooks turn it
into a yield.

## Bounding concurrency with a named pool

A scheduled method may also be an `#[Async]` method — the two compose. When the
scheduler fires it, the `#[Async]` proxy routes the body onto its executor, exactly
as it would for an API call. Point both at the same **named pool** and scheduled
runs and API-triggered runs share one bounded set of workers.

Register the pool once (a fixed-size executor) in your Boot's `providers()`:

```php
use Flytachi\Winter\K2\Concurrent\Executors;

protected static function providers(Container $c): void
{
    // at most 5 running at once; unbounded wait queue (the default)
    $c->singleton('mailPool', fn() => Executors::newFixedExecutor(5));
}
```

Then reference it by id from `#[Async]`:

```php
class MailService
{
    #[Scheduled(cron: '* * * * *')]   // the scheduler fires it every minute
    #[Async('mailPool')]              // …onto mailPool
    public function drain(): void { /* ... */ }
}

// an API endpoint can trigger the same work onto the same pool
$this->mail->drain();   // returns immediately, queued on mailPool
```

Both paths now funnel through `mailPool`: at most 5 concurrent runs across the
scheduler and the web workers **of one process**. Two things to keep in mind:

- **Register it as a `singleton`.** The cap lives in the pool instance's state, so
  every `#[Async('mailPool')]` call must resolve the *same* instance. A `bind()` /
  transient registration would hand out a fresh pool per call and the cap would
  never hold.
- **The pool is per-process.** The scheduler process and each web worker hold
  their own `mailPool` instance, so the cap is 5 *per process*, not 5 globally.
  A single shared cap across processes/hosts needs an external broker, not this
  in-memory pool.
- **With `#[Async]`, the scheduler's own no-overlap guard steps aside** (the async
  hand-off returns immediately), so overlap is governed by the pool. That is what
  the pool is for — bound it (`Executors::newFixedExecutor(5)`), and a slow run
  simply queues instead of piling up unbounded coroutines.

The pool enforces its cap only under Swoole (coroutines). Without coroutines
(FPM, plain CLI) there is no parallelism to bound, so tasks run sequentially and
the size is a no-op. For cost control set a bounded queue and a reject policy:

```php
use Flytachi\Winter\K2\Concurrent\RejectPolicy;

$c->singleton('mailPool', fn() => Executors::newFixedExecutor(
    concurrency: 5, queue: 50, onReject: RejectPolicy::DISCARD,
));
```

## Fork-safety under the non-Swoole runtime

Under Swoole — the intended runtime — a scheduled task runs in a coroutine and
shares the process's resources safely. Under the fork runtime, `spawn()` forks a
child per run and, unlike a daemon worker, does **not** run the `ForkReset` hooks
(a scheduler is not a daemon). A task that uses a fork-unsafe resource (a shared DB
connection, a pool) directly from a forked run can therefore corrupt it. If you
must run the Scheduler without Swoole, keep scheduled tasks fork-safe — open and
close their own resources inside the run.

## Discovering and running

List every task without starting anything — a static scan:

```
$ call schedule list
  TASK                                           TRIGGER
  App\ReportService::roll                        fixedDelay 5s
  App\ReportService::poll                        fixedRate 2s
```

Run it in the foreground (blocks) or detached:

```
call schedule start
call schedule start -d
```

Inspect and stop it from any terminal:

```
call schedule status
call schedule stop      # graceful SIGTERM
```

`SIGHUP` (or restarting) re-scans the annotated methods, so newly added or removed
tasks take effect without a full restart:

```
kill -HUP <scheduler-pid>
```

## Advanced: sourcing tasks yourself

Discovery is an override point. Subclass `Scheduler` and override `discover()` to
supply `ScheduledTask`s from another source — for example a table of cron rows —
instead of, or in addition to, annotation scanning:

```php
final class DbScheduler extends Scheduler
{
    protected function discover(): array
    {
        $tasks = parent::discover();          // keep annotated methods
        foreach ($this->rows() as $row) {
            $tasks[] = new ScheduledTask(
                $row->class,
                $row->method,
                new FixedRateTrigger($row->seconds),
            );
        }
        return $tasks;
    }
}
```
