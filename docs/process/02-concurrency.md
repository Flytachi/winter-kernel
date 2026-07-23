# Concurrency, the semaphore and back-pressure

The `EmailDispatchWorker` from the previous pages has a ceiling that has nothing
to do with the framework: it sends one email at a time. Each `send()` spends most
of its time waiting on the SMTP server — connecting, handshaking, waiting for a
`250 OK` — and while it waits, the worker does nothing else. If the queue holds
ten thousand emails and each send takes 200 ms of mostly-idle waiting, the worker
needs over half an hour, almost all of it spent blocked on the network.

The fix is to have several sends in flight at once, overlapping their waits. That
is what `spawn()` is for. And the moment you have several things in flight, you
need a way to say *how many* — otherwise a fast producer will launch work faster
than it completes and exhaust the machine. That limit, and the well-behaved way
it is enforced, is the substance of this page.

---

## `spawn()` — run a unit concurrently

`spawn()` hands a task to the runtime to run alongside the body, and returns a
`Future` — a handle you can use to wait for its result or discover its failure,
though for fire-and-forget work you usually ignore it.

Here is the email worker rewritten to send concurrently. The body's job is no
longer to *send* — it is to *dispatch*: pull the next email and hand it off, as
fast as the concurrency limit allows.

```php
final class EmailDispatchWorker extends Process
{
    protected int $concurrency = 25;      // up to 25 sends in flight at once

    #[Autowired] private MailQueue $queue;
    #[Autowired] private Mailer $mailer;

    public function run(): void
    {
        while ($this->isRunning()) {
            $job = $this->queue->reserve(timeout: 1.0);
            if ($job === null) {
                continue;
            }

            // hand this email to the runtime and immediately go get the next one
            $this->spawn(function () use ($job): void {
                try {
                    $this->mailer->send($job->to, $job->subject, $job->body);
                    $this->queue->markSent($job->id);
                } catch (DeliveryException) {
                    $this->queue->release($job->id);
                }
            });
        }
    }
}
```

Under Swoole each `spawn()` becomes a coroutine. When `send()` blocks on the
network the coroutine yields, the scheduler runs another coroutine, and the body
keeps reserving jobs — so up to twenty-five sends make progress at the same time
on a single thread. The half hour becomes roughly a minute and a half.

`spawn()` is built directly on the Concurrent unit
([`concurrent/00-overview.md`](../concurrent/00-overview.md)) — a spawned task is
an `Executors::common()->submit()` under the hood, which means it runs in its own
coroutine context and borrows its own connection from the shared PPA pool, so the
twenty-five concurrent sends do not fight over one database handle.

One guarantee underlies all of this and is worth stating plainly: **a process
always waits for every task it spawned to finish before it exits.** A parent never
outlives its children. This is structured concurrency, and it is the reason a
graceful stop drains the sends that are already in flight instead of dropping
them on the floor.

---

## `$concurrency` — the bound, and why it is not optional

The `protected int $concurrency` field is the maximum number of spawned tasks
allowed to run at once.

```php
protected int $concurrency = 25;
```

| Value | Behaviour |
|---|---|
| `0` (default) | **unlimited** — every `spawn()` starts immediately |
| `N` | at most `N` tasks run concurrently; further `spawn()`s wait for a slot |

It is tempting to read `0` as "as fast as possible" and reach for it, but it is a
trap, and it is worth understanding exactly why. With no limit, the dispatch loop
reserves a job and spawns a send, reserves another and spawns another, and never
pauses — because nothing makes it pause. If the SMTP server slows down, sends
start taking longer, but the loop keeps launching new ones at full speed. The
number of in-flight coroutines climbs without bound; each one holds a job, a
buffer, a connection. Memory grows until the worker is killed by the OOM killer,
and the slow SMTP server — the actual bottleneck — is now also drowning in
connections. An unbounded producer does not go faster; it goes down.

Setting `$concurrency` to a real number prevents this, but the interesting part is
*how* it prevents it.

---

## The mechanism: a counting semaphore

The limit is implemented as a **counting semaphore** — a pool of `N` permits. To
start a task, `spawn()` must first take a permit; when the task finishes, its
permit goes back into the pool. While all `N` permits are checked out, the next
`spawn()` cannot get one, so it **waits until a running task returns its permit.**

```
$concurrency = N   creates a permit pool of size N

spawn():
    take a permit          ← if none are available, WAIT here
    start the task
    ── task finishes ──►   return the permit   (which wakes one waiting spawn)
```

Under Swoole the permit pool is a `Swoole\Coroutine\Channel(N)` pre-loaded with
`N` tokens. Taking a permit is a `pop()`; when the channel is empty the calling
coroutine **suspends** — it does not spin, it does not burn CPU, and critically it
does not block the worker thread, so all the other coroutines keep running while
this one is parked. When a finishing task pushes its token back, the scheduler
resumes exactly one waiting `spawn()`. That suspension is the whole trick.

This is not a Winter invention; it is the canonical way to bound concurrent work,
and you will recognise it from other ecosystems:

- **Java** — the pattern in Goetz's *Java Concurrency in Practice* is literally a
  `Semaphore` wrapped around a thread pool; `acquire()` blocks the submitter when
  the pool is saturated.
- **Go** — the idiomatic bounded worker pool is a buffered channel used as a
  semaphore: `sem := make(chan struct{}, N)`, and sending to a full channel blocks
  the goroutine.
- **Kotlin / Reactive Streams** — a coroutine `Semaphore`, or first-class
  back-pressure where the consumer signals the producer to slow down.

The principle they share is the one that matters here: **when the workers are
saturated, slow down the producer.** Blocking the thing that submits work is not a
deficiency to be engineered away — it is the correct behaviour, and the unbounded
alternative is the classic mistake it exists to prevent.

---

## Back-pressure, in practice

The practical consequence of the semaphore is precise and worth internalising:
**`spawn()` is a point at which the body can pause.** When every permit is out, the
body stops *at the `spawn()` call* and does not proceed until a slot frees. Any
code that follows `spawn()` in the loop waits along with it.

You can see this most clearly by pushing the limit to its extreme, `concurrency =
1`, which serialises the tasks completely — task #2 cannot even begin until task
#1 has finished. Instrumenting a body that logs on either side of `spawn()` makes
the pause visible:

```php
protected int $concurrency = 1;

public function run(): void
{
    while ($this->isRunning()) {
        $job = $this->queue->reserve(timeout: 1.0);
        if ($job === null) {
            continue;
        }
        $this->logger->debug("about to dispatch #{$job->id}");
        $this->spawn(fn() => $this->handle($job));   // waits here while a task holds the permit
        $this->logger->debug("dispatched #{$job->id}");
    }
}
```

```
about to dispatch #41    12:00:00.000
dispatched #41           12:00:00.001     ← permit was free, taken instantly
about to dispatch #42    12:00:00.100
dispatched #42           12:00:03.100     ← +3s: waited for #41's task to return the permit
```

Nothing is lost in this waiting — every `spawn()` you call still runs; the body is
simply *paced* to the rate at which tasks complete, which is exactly what stops it
from overwhelming the workers or the memory. Where you place a log line relative to
`spawn()` determines whether it fires on every iteration (put it before) or only
once a permit is actually acquired (put it after).

> Back-pressure paces the *producer*; it is not a durable queue. In-flight tasks
> live in memory, so a `SIGKILL`, a crash, or a `grace` timeout drops whatever was
> running. When the work itself must survive a crash — a payment, a queued message
> — durability has to come from the source: reserve-and-acknowledge against the
> broker or database, so an un-acknowledged job is redelivered. `spawn()` gives you
> concurrency and back-pressure, not persistence; the two are separate concerns and
> should stay that way.

---

## Activity — `IDLE` and `BUSY`

Separately from its lifecycle state, a process reports whether it is *doing work
right now*. This is its **activity**, and it takes one of two values, `BUSY` or
`IDLE`. Activity drives three things: the drain-to-idle behaviour of a graceful
stop (a `BUSY` process is never interrupted mid-work), the live status shown by
the CLI and web, and — for a supervised `Daemon` — the decision of which workers
are safe to stop when scaling down, since you only ever want to remove an idle one.

A process is `BUSY` when **either** an inline unit is marked in progress **or** any
`spawn()`ed task is still running, and `IDLE` otherwise. The common case needs no
work from you at all: because `spawn()` tracks its own in-flight count, a worker
that dispatches through `spawn()` reports `BUSY`/`IDLE` automatically.

You only mark activity by hand when you process a unit **inline** — directly in
the body, without `spawn()` — because then there is no in-flight count for the
framework to observe:

```php
final class ReportBuilder extends Process
{
    #[Autowired] private ReportQueue $queue;

    public function run(): void
    {
        while ($this->isRunning()) {
            $request = $this->queue->reserve(timeout: 1.0);
            if ($request === null) {
                continue;               // IDLE — parked waiting for a request
            }

            $this->markBusy();          // a report build starts — protect it from mid-work interruption
            $this->buildReport($request);   // minutes of CPU-bound work, run inline
            $this->markIdle();          // done — safe to interrupt or scale down again
        }
    }
}
```

`markBusy()` and `markIdle()` are pure in-memory flags — they cost nothing and are
safe to call on every iteration. They combine with the spawn count by OR, so a
process that both marks itself busy and has spawns in flight stays `BUSY` until
both are clear; it can never report a false `IDLE` while work is genuinely
outstanding. The value is persisted to the status record on a roughly one-second
heartbeat, and only when it actually changes, so a worker flipping between busy
and idle on every message never touches the disk for it.

---

## How the FPM backend reproduces this

When Swoole is absent there are no coroutines, so the backend runs each `spawn()`
as a **forked child process**. Every behaviour above is preserved — the cap, the
back-pressure, the serialisation at `concurrency = 1` — but the machinery is
different, and one difference is observable and worth knowing.

| | Swoole | PHP-FPM / CLI |
|---|---|---|
| A `spawn()` becomes | a coroutine | a `pcntl_fork()` child process |
| The permit pool is | a `Coroutine\Channel(N)`; taking a permit **suspends** the coroutine | a count of live children; a full pool **blocks** the parent on `pcntl_wait()` |
| Waiting for a permit | non-blocking — other coroutines keep running | a genuine block — the parent has nothing else to do meanwhile |
| The task's memory | shared with the body | an isolated copy taken at fork time |
| The `Future` result | the task's real return value | a settled placeholder — a child cannot return a value across the process boundary |

The back-pressure has the same shape on both. At `concurrency = 1` under FPM, the
parent reaches the second `spawn()`, sees the one permit is taken, and blocks in
`pcntl_wait()` until the running child exits — then forks the next. The tasks run
strictly one after another, and a trace shows the identical pause you saw under
Swoole.

The one behaviour that genuinely differs comes from process isolation, and you
should know it before you rely on cooperative cancellation. A Swoole `spawn()` is
a coroutine sharing the body's memory, so it observes `isRunning()` flip to false
the instant a stop is requested and can break out of its own loop early. An FPM
`spawn()` is a separate process holding its own copy of that flag as it stood at
fork time: a child that was already running when the stop arrived does **not** see
the later `requestStop()`, and runs its task to completion. The parent waits for
it in `pcntl_wait()` and then exits. This is ordinary, correct fork semantics, but
it means that under FPM an already-running spawned task cannot be cancelled
mid-flight the way a coroutine can — it finishes what it started.

---

## Where `spawn()` fits, and where it does not

```
Daemon  ──►  N separate Process workers      isolation · independent scaling · CPU parallelism
                        │
                        └──►  spawn() inside each      I/O concurrency within one worker
```

`spawn()` gives you concurrency *inside a single process*: many units overlapping
their I/O on one runtime. It does not give you fault isolation — an uncaught fatal
error in one coroutine takes down every coroutine in that process — nor
parallelism across CPU cores, because the coroutine scheduler is single-threaded.
When you need those, you run several separate *processes*, each isolated and
individually restartable, under a supervisor. That supervisor is a `Daemon`, and
the two layers compose cleanly: a supervised worker still uses `spawn()` internally
to overlap its own I/O. Choosing between them is a question of what you are
protecting against — the machine running out of memory (bound with `$concurrency`)
versus one bad job taking down the whole fleet (isolate with separate processes).

---

Next: [03-control.md](03-control.md) — launching, stopping and inspecting a process
from the CLI and the web.
