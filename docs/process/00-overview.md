# Winter Process — Overview

Most of what a web framework does is shaped like a request: something comes in,
you compute an answer, you send it back, and the worker is free again in
milliseconds. A large class of real work does not fit that shape at all.

An outbound-email worker has to sit on a queue for days, sending as jobs arrive.
A media service has to turn uploaded videos into thumbnails without blocking the
upload. A billing integration has to hold a persistent connection to a payment
gateway open around the clock. A reporting job has to walk millions of rows at
3 a.m. and assemble a file. None of these is a request. Each one has to be
*started*, *observed while it runs*, and *stopped cleanly* — and each has to keep
running long after the shell that launched it has closed.

**Process** is the unit for that work. You write the logic as an ordinary class;
the framework wraps it in a managed lifecycle — start, stop and status from the
CLI and the web — and supplies the machinery underneath: a runtime that is either
Swoole coroutines or forked processes, a bounded concurrency model, cooperative
cancellation so a running worker can be interrupted safely, and cleanup that is
guaranteed to run however the process ends.

Here is a complete outbound-email worker. It is the example this documentation
returns to, because a queue consumer exercises nearly everything a process does:

```php
final class EmailDispatchWorker extends Process
{
    #[Autowired] private MailQueue $queue;
    #[Autowired] private Mailer $mailer;

    public function run(): void
    {
        while ($this->isRunning()) {
            $job = $this->queue->reserve(timeout: 1.0);   // next pending email, or null
            if ($job === null) {
                continue;                                  // queue empty — poll again
            }

            try {
                $this->mailer->send($job->to, $job->subject, $job->body);
                $this->queue->markSent($job->id);
            } catch (DeliveryException $e) {
                $this->queue->release($job->id);           // return it for a retry
                $this->logger->warning("delivery failed for {$job->to}: {$e->getMessage()}");
            }
        }
    }
}
```

Nothing in that class knows whether it is running on top of a coroutine scheduler
or a forking process. It reserves a job, sends it, acknowledges it, and loops.
Operating it is three commands:

```
call process main.EmailDispatchWorker start -d     # launch, detached from the shell
call process main.EmailDispatchWorker status -v    # is it alive? busy? how much memory?
call process main.EmailDispatchWorker stop          # stop it gracefully
```

---

## The central idea: you own the body, the framework owns everything else

The method you write, `run()`, is plain application code. It never opens an event
loop, never forks, never installs a signal handler, never writes a PID file. It
reads as if it were the only thing running.

Everything the body *needs* from its environment arrives through a small set of
protected primitives — `isRunning()` to drive its loop, `sleep()` for
interruptible pauses, `spawn()` to run work concurrently. Everything the body
should *react to* — a stop request, a reload signal, a fatal exit — arrives
through overridable hooks such as `onTerminate()`, `onReload()` and
`onShutdown()`. The two sets are small enough to hold in your head, and they are
the entire contract.

The payoff is that the same class is correct on two very different runtimes. When
Swoole is present the body runs inside a coroutine scheduler and `spawn()` yields
lightweight coroutines that interleave on I/O. When it is not, the body runs in a
plain process and `spawn()` forks children. The framework chooses; the body does
not change. This is the design goal stated once and enforced everywhere:
**one body, two runtimes, identical observable behaviour.**

---

## A process is whatever its body is

A process is not a specific structure like "a worker pool." The body dictates the
shape, and three shapes cover almost everything.

**A one-shot task** runs to completion and exits. There is no loop; `run()` does
its work and returns.

```php
final class ExpiredSessionCleanup extends Process
{
    #[Autowired] private SessionRepository $sessions;

    public function run(): void
    {
        $removed = $this->sessions->deleteOlderThan(days: 30);
        $this->logger->info("purged {$removed} expired sessions");
        // run() returns → the process is finished and exits
    }
}
```

**A long-lived consumer** loops until it is told to stop. This is the
`EmailDispatchWorker` above, and the most common shape by far.

**A concurrent worker** is a consumer whose loop hands each unit to `spawn()`, so
the units run in parallel instead of one at a time — the right shape when the work
is I/O-bound and the units are independent. That is the subject of
[02-concurrency.md](02-concurrency.md).

Concurrency is opt-in. A process that never calls `spawn()` is an ordinary
sequential program, and that is a perfectly good thing to be.

**Triggered by a clock, not a loop?** When the question is *when* to run rather
than *how* to loop — every night at 02:00, every five minutes — you usually do
not write a Process at all: annotate a plain method with `#[Scheduled]` and the
**Scheduler** runs it on time. The Scheduler is itself a Process, built on
everything this page describes; see [`schedule/`](../schedule/00-overview.md).

---

## The two runtimes

The framework selects the backend from the loaded extension: if the Swoole
extension is available the body runs on coroutines, otherwise on forks. The
selection is invisible to your code, but the mechanics differ enough that it is
worth seeing side by side.

```
                        run()  (your body)
                              │
              ┌───────────────┴────────────────┐
        Swoole extension present           no Swoole  (PHP-FPM / CLI)
              │                                 │
     Swoole\Coroutine\run()               a plain OS process
     spawn()  → a coroutine               spawn()  → pcntl_fork()
     sleep()  → yields, non-blocking      sleep()  → a real pause
     tasks share the body's memory        each task is an isolated child
```

| | Swoole | PHP-FPM / CLI |
|---|---|---|
| The body runs inside | `Swoole\Coroutine\run()` | the process itself |
| `spawn()` produces | a coroutine (~12 KB, µs to start) | a forked child process (MBs, ms to start) |
| `sleep()` | yields — other coroutines keep running | pauses this process |
| Concurrent tasks share memory | yes — they observe `isRunning()` directly | no — a child has its own copy of everything |
| Parallelism | many coroutines overlapping on I/O, one core | one running task per forked child |

Everything a process *is* — its lifecycle, the way it stops, the concurrency cap
and back-pressure — is identical on both backends. What differs is the physics
underneath: coroutines are cheap and share memory, forks are heavier and
isolated. The following pages describe each mechanism once and then, where the
difference is observable, spell out how the FPM backend is adapted to match.

**One boundary is worth stating up front.** Coroutines give you *concurrency* —
many tasks making progress by overlapping their I/O — but not *parallelism*
across CPU cores, because a coroutine scheduler runs on a single thread. When you
need genuine parallelism, fault isolation, or workers that scale up and down
independently, you run several separate *processes* under a supervisor. That
supervisor is a `Daemon` (a supervised Process), and `spawn()` is the concurrency
layer *inside* a single worker — the two compose rather than compete.

---

## Pages

| # | File | Contents |
|---|------|----------|
| 00 | this page | What a process is, the two runtimes, the shapes it takes |
| 01 | [01-lifecycle.md](01-lifecycle.md) | Writing the body, cooperative stop, signals, guaranteed cleanup |
| 02 | [02-concurrency.md](02-concurrency.md) | `spawn`, the concurrency cap, the semaphore and back-pressure |
| 03 | [03-control.md](03-control.md) | Starting, stopping and inspecting a process; the CLI |

## See also

- [`concurrent/00-overview.md`](../concurrent/00-overview.md) — `Executors` and `Future`, the primitive `spawn()` is built on
- [`schedule/00-overview.md`](../schedule/00-overview.md) — the Scheduler, a Process that runs `#[Scheduled]` methods on a clock
- [`process/daemon/00-overview.md`](daemon/00-overview.md) — a supervised fleet of identical processes
- [`ppa/00-overview.md`](../ppa/00-overview.md) — the database connection pool a process shares across its coroutines
