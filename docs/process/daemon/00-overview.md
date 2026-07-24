# Winter Daemon — Overview

A [Process](../00-overview.md) is **one** managed worker: one body, one PID, alive
until you stop it. That is enough for a single consumer or a single scheduled job.
It is not enough when one worker cannot keep up, when a crash must not take the
work down with it, or when the load rises and falls through the day and you want
the number of workers to follow it.

**Daemon** is the answer to all three. A daemon is the same `Process` body, but run
as a **fleet of identical worker replicas** kept alive by a **master supervisor**.
The master does no work itself — it forks workers, watches them, restarts the ones
that die, and grows or shrinks the fleet to match demand. The mental model is
exactly `nginx` or `php-fpm`: a `master process` over a pool of `worker process`es;
or a Kubernetes Deployment whose controller keeps a set of pods at the size you
declared.

```
winter-daemon: EmailDispatch master        ← supervisor (does NOT run the body)
├── winter-daemon: EmailDispatch worker#1   ← a Process worker
├── winter-daemon: EmailDispatch worker#2
└── winter-daemon: EmailDispatch worker#3
```

You write config plus a body; the daemon supplies everything hard about running a
fleet: the two-tier process tree, forking and reaping, restart with back-off, a
graceful stop that drains the whole fleet, autoscaling with damping, and a
crash-safe singleton lock. As with `Process`, the same code runs whether the
workers are Swoole coroutine processes or plain forks.

## Two ways to give the daemon its body

The manager and the unit of work are separate concerns (like an executor and its
task), so the worker body is supplied in one of two ways. The daemon picks the
first that applies:

**1. Inline — `workerRun()`.** Define this method and the daemon *is* the worker;
its body runs in every replica. Simplest, one file:

```php
final class EmailDispatch extends Daemon
{
    #[Autowired] private MailQueue $queue;

    protected int $replicas = 3;

    protected function workerRun(): void
    {
        while ($this->isRunning()) {
            $job = $this->queue->pop(timeout: 1.0);
            if ($job === null) {
                continue;
            }
            $this->markBusy();
            $this->send($job);
            $this->markIdle();
        }
    }
}
```

**2. External — `$workerClass`.** Point at a standalone [`Process`](../00-overview.md)
class. The worker is then reusable on its own (run it solo with `SendProcess::start()`)
*and* supervisable under the daemon:

```php
final class EmailDispatch extends Daemon
{
    protected int $replicas = 3;
    protected ?string $workerClass = SendProcess::class;
}
```

If neither is provided the master still starts, but each worker fails on fork with
a `DaemonConfigException` (there is no body to run). The priority is fixed:
**`workerRun()` first, `$workerClass` second.** See
[Workers](01-workers.md) for how a worker inherits DI across the fork and the
`afterFork()` seam that keeps its connections safe.

## What the master does for you

Everything below the body is the supervisor's job — you never write it:

| Concern | Handled by the master |
|---|---|
| Keep N workers alive | forks replicas, reaps exits |
| A worker crashed | restart per [`RestartPolicy`](02-autoscaling.md#restartpolicy) with exponential back-off, into the same slot |
| Load changed | drive the fleet to [`desiredReplicas()`](02-autoscaling.md), damped by [`ScalingPolicy`](02-autoscaling.md#scalingpolicy) |
| Stop requested | drain the whole fleet gracefully, then exit ([Control](03-control.md#stopping-the-fleet)) |
| One instance per class | crash-safe `flock` singleton |
| Observe the fleet | per-worker status (`call daemon … status`) |

## When a daemon, when a bare process

- **Bare [`Process`](../00-overview.md)** — a single coordinator, a leader-elected
  singleton, one long loop. Not a fleet.
- **`Daemon`** — several identical workers that must stay alive, scale, or survive
  crashes independently. A queue consumer pool, an SMPP bind pool, a fan-out of
  pollers.

## Where to go next

- **[Workers](01-workers.md)** — the two body forms, DI and fork-safety
  (`afterFork()` / `ForkReset`), `spawn()` inside a worker.
- **[Autoscaling & restart](02-autoscaling.md)** — `desiredReplicas()`, the
  `ScalingPolicy` damping model, `RestartPolicy`, and the master hooks.
- **[Control](03-control.md)** — `start` / `dispatch` / `status` / `stop`, the CLI,
  the stop sequence, and the per-worker fleet view.
