# Lifecycle, stop and signals

The hardest part of a long-running process is not starting it — it is stopping it
correctly. A deploy, a scale-down, a `systemctl restart`, an operator pressing
Ctrl-C: all of them ask the process to end, and the process has to end *well*. It
must not be cut off in the middle of sending an email or halfway through a
database transaction. It must release the connections and locks it holds. And it
has to be stoppable even at the moment it is blocked waiting on a socket that may
stay quiet for minutes.

Getting this right is a solved problem, and the solution is the same one Java,
Go, and Kubernetes all arrived at independently: **cooperative cancellation with a
forced deadline behind it.** The process is *asked* to stop; the body itself
decides the safe point at which to actually do so; and if the body never reaches
such a point, a deadline forces the process down anyway. Winter's Process
implements exactly this model, and this page is the small surface you use to
participate in it.

---

## `isRunning()` — the cooperative signal

The backbone of the whole mechanism is a single boolean the body polls. It is
`true` while the process should keep working and becomes `false` the moment a stop
is requested — whether that request came from `SIGTERM`, from the CLI `stop`
command, or from the body calling `requestStop()` on itself.

A loop driven by `isRunning()` therefore stops **between** units of work, never in
the middle of one:

```php
final class EmailDispatchWorker extends Process
{
    #[Autowired] private MailQueue $queue;
    #[Autowired] private Mailer $mailer;

    public function run(): void
    {
        while ($this->isRunning()) {          // re-checked before every job
            $job = $this->queue->reserve(timeout: 1.0);
            if ($job === null) {
                continue;
            }
            $this->mailer->send($job->to, $job->subject, $job->body);
            $this->queue->markSent($job->id);
            // if a stop was requested during send(), the loop exits cleanly here,
            // *after* this email is fully sent and acknowledged
        }
    }
}
```

This is the direct analogue of Java's `while (!Thread.currentThread().isInterrupted())`:
a flag you check at the points where stopping is safe. You are never obliged to
call anything to make a process stop. If `run()` returns on its own — a one-shot
task, or a loop that reached its natural end — the process is finished. If a stop
is requested from outside, the framework flips the flag for you; your only
responsibility is to test it.

---

## Interruptible waits — `sleep()` and `InterruptedException`

A polled flag has a blind spot. In the worker above, `reserve(timeout: 1.0)`
returns at least once a second, so the loop re-checks `isRunning()` promptly. But
consider a process that legitimately blocks for a long time — waiting thirty
seconds between health checks, or parked on a socket read. A flag alone would let
it sit out the entire wait before noticing the stop.

To close that gap, the framework's own blocking primitive is **interruptible**.
When a stop arrives while the body is parked in `sleep()`, the wait is abandoned
immediately and throws `InterruptedException` — it does not run out the clock:

```php
final class HealthReporter extends Process
{
    #[Autowired] private HealthCheck $check;
    #[Autowired] private StatusdClient $statusd;

    public function run(): void
    {
        while ($this->isRunning()) {
            $this->statusd->report($this->check->run());
            $this->sleep(30);          // a 30-second wait, but interruptible
        }
    }
}
```

If this process is stopped nineteen seconds into a `sleep(30)`, it does not wait
the remaining eleven — the `sleep()` throws at once and the body unwinds. This is
precisely how Java behaves: `Thread.sleep()`, `Object.wait()`,
`BlockingQueue.take()` throw `InterruptedException` when the thread is interrupted
mid-block, so a blocked thread wakes instantly rather than after its timeout.

Because it is an exception, you decide how much ceremony to give it. The common
case is none — let it propagate:

```php
public function run(): void
{
    while ($this->isRunning()) {
        $this->statusd->report($this->check->run());
        $this->sleep(30);              // may throw; nothing to clean up, so we let it
    }
    // an interrupt during sleep() simply unwinds out of run() — a graceful stop
}
```

The other case is when you have work in flight that must be settled before you
stop — a reserved job that should go back on the queue rather than be lost:

```php
public function run(): void
{
    $job = null;
    try {
        while ($this->isRunning()) {
            $job = $this->queue->reserve(timeout: 30.0);   // interruptible block
            if ($job === null) {
                continue;
            }
            $this->mailer->send($job->to, $job->subject, $job->body);
            $this->queue->markSent($job->id);
            $job = null;
        }
    } catch (InterruptedException) {
        // stopped while parked in reserve() — no email was half-sent, nothing to undo
    } finally {
        if ($job !== null) {
            $this->queue->release($job->id);   // hand a reserved-but-unsent job back
        }
    }
}
```

Two details distinguish this from Java and remove its sharpest edge. First,
`finally` blocks always run on the way out, so cleanup is guaranteed even when the
interrupt propagates. Second, catching `InterruptedException` does **not** clear
any flag — `isRunning()` is a separate, sticky signal — so there is no equivalent
of Java's notorious "swallowed interrupt" bug, where catching the exception hides
the fact that a stop was ever requested.

> Interruptibility is real coroutine cancellation under Swoole. A native PHP
> `sleep()`, or a blocking call in a client that is not coroutine-aware, cannot be
> interrupted this way — it will run to completion regardless. Use `$this->sleep()`
> and coroutine-aware clients for any wait that should be interruptible. Under
> FPM, `$this->sleep()` is implemented as a series of short slices, so an
> interrupt is noticed within one slice rather than instantly, but the effect is
> the same.

---

## The stop sequence, end to end

With those two primitives in place, a `stop` (which sends `SIGTERM`) drives the
following sequence. Read it as the definition of "stopping well":

```
1.  isRunning() flips to false           the body stops accepting new work
2.  onTerminate() runs                    your reaction — log it, flush a metric
3.  the body reaches a safe point:
      · parked in an interruptible wait  → the wait throws, the body unwinds now
      · in the middle of a unit of work  → the unit finishes; it is never cut off
4.  in-flight spawn()ed tasks drain       the process waits for its own children
5.  the body returns → onShutdown() runs  guaranteed teardown, on every path
        └─ if draining outlasts $grace  → the process is forced down (see below)
```

Steps 3 and 4 are what make a stop *graceful* rather than merely fast. A unit of
work already in progress is allowed to complete — the framework distinguishes a
process that is *waiting* for work (safe to interrupt at once) from one that is
*doing* work (finished first). That distinction is the process's **activity**,
`IDLE` versus `BUSY`, and how it is tracked — automatically for `spawn()`, or
explicitly with `markBusy()` — is covered in [02-concurrency.md](02-concurrency.md).

Underpinning step 4 is a firm guarantee: **a process always waits for the tasks it
spawned to finish before it exits.** It never abandons its children. This is
structured concurrency, and it is why a graceful stop drains outstanding work
instead of dropping it.

---

## `grace` — the drain deadline

Draining is bounded by `grace`: it is *how long the process will wait for
in-flight work to finish before it stops waiting and forces itself down.* It is a
drain deadline — deliberately **not** a blunt "kill this process after N seconds"
timer, which would cut off legitimate long-running work.

```php
final class VideoTranscodeWorker extends Process
{
    // a single transcode can take minutes; do not let a deploy kill one mid-flight
    protected float $grace = 0.0;   // wait for the current job to finish, however long
}
```

| `$grace` | Meaning |
|---|---|
| `0.0` (default) | wait for in-flight work to drain **for as long as it takes** — never force by a timer |
| `> 0` | wait up to *N* seconds for the drain, then force the process down |

The choice is a policy decision about your work. If dropping an in-progress unit
is worse than a slow shutdown — a video transcode, a large export — keep `grace`
at `0` and let an external `SIGKILL` (or, for queue work, the broker's redelivery)
be the ultimate stop. If you need a hard ceiling — a container orchestrator that
will `SIGKILL` after thirty seconds anyway — set `grace` to just under that so the
process controls its own forced exit. A repeated `stop`, or `kill -9`, always
forces immediately, regardless of `grace`.

---

## Signals

A process traps the conventional daemon signal set and routes each signal to an
overridable hook. It is important to understand the division of responsibility:
for the stop signals, **the framework guarantees the stop** — the hook is your
chance to *react*, not a veto. You cannot accidentally ignore a `SIGTERM` by
forgetting to act on it; the process will stop regardless.

| Signal | Hook | Default behaviour |
|---|---|---|
| `SIGTERM` | `onTerminate()` | graceful stop — this is what `stop` and orchestrators send |
| `SIGINT` | `onInterrupt()` | graceful stop — an interactive Ctrl-C |
| `SIGHUP` | `onReload()` | **reload configuration — the process keeps running** |
| `SIGUSR1` | `onUser1()` | a user-defined action — no-op by default |
| `SIGUSR2` | `onUser2()` | a user-defined action — no-op by default |
| `SIGPIPE` | — | ignored, so a write to a closed socket never kills the process |

`SIGHUP` follows the long-standing daemon convention that a hang-up means *reload*,
not *stop* — the same signal nginx and Apache use to re-read their configuration
without dropping a connection. It is the one place to make a running process pick
up new settings:

```php
final class ApiPoller extends Process
{
    private RateLimit $limit;

    #[Autowired] private ConfigStore $config;
    #[Autowired] private UpstreamApi $api;

    public function run(): void
    {
        $this->limit = $this->config->rateLimit();     // initial config
        while ($this->isRunning()) {
            $this->api->poll();
            $this->sleep($this->limit->intervalSeconds());
        }
    }

    protected function onReload(): void
    {
        // operator ran `kill -HUP` after changing the config file;
        // pick up the new rate without a restart, keep running
        $this->limit = $this->config->rateLimit();
        $this->logger->notice("reloaded: interval now {$this->limit->intervalSeconds()}s");
    }
}
```

`SIGUSR1` and `SIGUSR2` are the two signals POSIX leaves for the application to
define — the standard way to trigger a one-off action on a running process without
restarting it (nginx, for example, uses them to reopen log files and to begin a
binary upgrade). A common pair is "show me what you are doing" and "change how
loudly you report it," neither of which should stop the worker:

```php
private bool $verbose = false;

protected function onUser1(): void
{
    // operator wants a live snapshot of internal counters, without stopping the worker
    $this->logger->info('metrics', $this->metrics->snapshot());
}

protected function onUser2(): void
{
    // flip verbose logging on a running process to debug an incident, then flip it back
    $this->verbose = !$this->verbose;
    $this->logger->notice($this->verbose ? 'verbose logging enabled' : 'verbose logging disabled');
}
```

---

## `onShutdown()` — cleanup that always runs

A `finally` block in `run()` covers the graceful path, but not a forced exit past
the `grace` deadline, and not a fatal error. For teardown that must happen on
**every** exit path — releasing a distributed lock, deregistering from a service
registry, flushing a buffer — override `onShutdown()`. The framework calls it
exactly once, whether the process ended gracefully, was forced down, or crashed:

```php
final class LeaderElectedWorker extends Process
{
    #[Autowired] private DistributedLock $lock;

    public function run(): void
    {
        $this->lock->acquire('report-generator');   // only one instance may run
        while ($this->isRunning()) {
            $this->generateNextReport();
        }
    }

    protected function onShutdown(): void
    {
        // must run even on a forced or fatal exit, or the lock would be held
        // until its TTL expires and no other instance could take over
        $this->lock->release('report-generator');
    }
}
```

The two levels are deliberate and complementary. The `finally` inside `run()` is
your local cleanup for the cooperative path — closing what this particular loop
opened. `onShutdown()` is the guaranteed hook for critical, externally-visible
teardown that has to run no matter how the process dies.

---

## `requestStop()` — stopping from the inside

Everything so far is about a stop arriving from *outside*. Sometimes the body
itself decides it is done — it consumed a poison-pill message, hit a
non-recoverable error, or finished the batch it was created for. `requestStop()`
is how it stops itself: it is the cooperative equivalent of the process sending
itself a `SIGTERM`. `isRunning()` flips to false everywhere — in the top-level
loop, in any nested loop, and in any `spawn()`ed task that checks it:

```php
public function run(): void
{
    while ($this->isRunning()) {
        $job = $this->queue->reserve(timeout: 1.0);
        if ($job === null) {
            continue;
        }
        if ($job->isShutdownSignal()) {
            $this->logger->notice('received shutdown job, draining and exiting');
            $this->requestStop();      // stop the whole process, not just this loop
            break;
        }
        $this->process($job);
    }
}
```

For a plain top-level loop, `break` or `return` alone is enough to end the body.
Reach for `requestStop()` when the intent to stop has to be visible beyond the
current stack — to a nested loop, or to concurrent tasks that are checking
`isRunning()` to wind themselves down.

---

Next: [02-concurrency.md](02-concurrency.md) — running many units at once, capping
how many, and the semaphore that turns the cap into back-pressure.
