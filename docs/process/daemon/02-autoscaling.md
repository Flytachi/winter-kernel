# Autoscaling & Restart

The master's job is to keep the fleet at the right size and to heal it. Two
policies shape that — one for *how big* the fleet should be, one for *what to do
when a worker dies* — plus a set of hooks for reacting to fleet events. All are
optional: the defaults run a fixed fleet that restarts on failure.

## Fixed size — `$replicas`

The simplest daemon just declares a size:

```php
final class Emails extends Daemon
{
    protected int $replicas = 3;   // keep three workers alive
}
```

The supervisor forks three workers, restarts any that crash, and keeps the count
at three. Nothing else is needed.

## Dynamic size — `desiredReplicas()`

Override `desiredReplicas()` to make the fleet follow load. It returns *how many
workers should run right now*; the supervisor drives the fleet toward it. This is
a declarative target (like a Kubernetes replica count), not an imperative command —
you say the number, the controller reconciles reality to it.

```php
protected function desiredReplicas(): int
{
    // one worker per 100 queued messages, capped at 16
    return min(16, max(1, intdiv($this->queue->depth(), 100)));
}
```

`desiredReplicas()` runs on the master about once per `scaleInterval`. It is a
plain method — read a queue depth, a metric, a config value. The default returns
`$replicas`.

## Stability over speed — `ScalingPolicy`

`desiredReplicas()` is a **signal, not a command**. If it flickers — a naive or
noisy implementation — reacting to every reading would thrash the fleet. The
supervisor damps it, and the damping is asymmetric, the way mature autoscalers do
it (Kubernetes HPA, cloud auto-scaling groups): **scale up quickly, scale down only
when low demand is sustained.**

Three knobs, on three different axes, tuned by a `ScalingPolicy`:

| Knob | Default | Axis — what it controls |
|---|---|---|
| `scaleDownStabilization` | `60.0` s | *whether* to shrink — low demand must hold this long (shrink only to the high-water demand over the window) |
| `scaleStep` | `0` (∞) | *how many* workers change per action — a gentle ramp, not a cliff |
| `cooldown` | `3.0` s | *how often* an action may happen |
| `scaleUpDelay` | `0.0` s | a rise must be sustained this long before scaling up (0 = at once) |
| `scaleInterval` | `1.0` s | how often `desiredReplicas()` / `tick()` are polled |

**Why `scaleDownStabilization` matters.** Say the fleet is at 10 and demand dips to
4 for a moment, then jumps back to 9. Without the window you would kill 6 workers,
then scramble to spawn them again — a saw-tooth. With it, scale-down uses the
**maximum** demand over the window, so a transient dip sheds nothing; the fleet
shrinks only after low demand has held for the whole window.

**Why `scaleStep` matters.** Going from 10 to 2 all at once drops capacity off a
cliff (and going 2 → 40 forks a thundering herd). `scaleStep: 2` moves at most two
workers per action, ramping gradually in either direction.

The defaults are tuned for a daemon — most never touch them. To tune, override
`scaling()`; the object is immutable and non-`final`, so you can also define reusable
named profiles:

```php
// one-off
protected function scaling(): ScalingPolicy
{
    return new ScalingPolicy(scaleDownStabilization: 120.0, scaleStep: 2);
}

// reusable profile
final class ConservativeScaling extends ScalingPolicy
{
    public function __construct()
    {
        parent::__construct(scaleDownStabilization: 300.0, cooldown: 30.0, scaleStep: 1);
    }
}
```

Scale-down is always **graceful**: chosen workers are sent SIGTERM and drain to
exit (IDLE ones first, so work in progress is never cut off). See
[Control — Stopping the fleet](03-control.md#stopping-the-fleet) for the drain
deadline that bounds it.

## Restart — `RestartPolicy`

When a worker dies *unexpectedly* (not a scale-down, not a stop), the restart
policy decides what happens. It groups three knobs:

```php
protected function restart(): RestartPolicy
{
    return new RestartPolicy(
        mode: RestartMode::ALWAYS,   // ALWAYS | ON_FAILURE | NEVER
        maxRestarts: 0,              // give up after N restarts across the fleet (0 = unlimited)
        backoff: 1.0,               // base seconds, exponential
    );
}
```

- **`RestartMode`** — `ALWAYS` (keep the worker alive on any exit), `ON_FAILURE`
  (restart only on a crash / non-zero exit; a clean exit is final — the default),
  `NEVER`.
- **`maxRestarts`** — a ceiling on total restarts across the fleet; exceeding it
  puts the daemon in the `FAILED` state and stops it.
- **`backoff`** — exponential back-off between restarts (`base × 2^(n-1)`, capped),
  so a crash-looping worker does not spin.

A restart re-forks into the **same slot**, so `worker#{n}` stays stable across a
worker's lifetime. Critically, an intentionally retired worker (scale-down or stop)
is **never** restarted — the supervisor tracks each slot's intent, so the restart
policy and the autoscaler never fight over it.

## Master hooks

Optional callbacks on the master for reacting to fleet events and for imperative
periodic work. All run on the supervisor, not in a worker:

| Hook | Fires |
|---|---|
| `tick()` | about once per `scaleInterval` — poll metrics, drive custom logic |
| `onWorkerStart(int $slot, int $pid)` | a worker was forked |
| `onWorkerExit(int $slot, int $pid, bool $crashed)` | a worker exited (`$crashed` = abnormal exit of a live worker) |
| `onScale(int $from, int $to)` | the fleet size changed |
| `onReload()` | SIGHUP reached the master |

`tick()` and `desiredReplicas()` are the two periodic seams, and they differ by
intent: `desiredReplicas()` is **declarative** (return a number, the supervisor
reconciles), `tick()` is **imperative** (do whatever — poll an API, refresh a
config). `onScale()` is the *notification* that a change happened; it is not where
the decision is made.

```php
protected function onScale(int $from, int $to): void
{
    $this->logger->info("scaled {$from} → {$to}");
}
```
