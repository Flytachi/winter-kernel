# Daemon — supervised, single-instance, stateful service

A `Daemon` is a long-running `Dispatchable` with three things `Process`
doesn't have:

1. **Cluster lock** — `dispatch()` refuses to start if an instance is
   already running.
2. **Persistent status** via `DaemonStore` — `Class::status()` and
   `Class::stop()` work from any other PHP process on the same box.
3. **A rate-limited fork engine** (`streaming()`) for "keep N children
   busy" patterns.

Use it for services you want supervised by a real init system
(systemd / Docker `restart: always`) and operable from CLI.

---

## Stereotype

```php
namespace App\Threads\Daemons;

use Flytachi\Winter\K2\Stereotype\Daemon;
use Flytachi\Winter\DI\Attribute\Autowired;

class Cleanup extends Daemon
{
    #[Autowired]
    private LogRotator $rotator;

    public function resolution(mixed $data = null): void
    {
        $this->prepare(streamRps: 10);   // optional — sets condition + rate

        $this->streaming(
            complianceCallable: function () {
                $this->fork(fn() => $this->rotator->rotateOne());
            },
        );
    }
}
```

`Daemon extends ThreadDaemon extends Dispatch`. `exNamespace = 'daemon'`.
Mixes in `ThreadDaemonFork`, `ThreadDaemonHandler`, `ThreadSignalHandler`,
and `ThreadDaemonStatement`.

DI works the same — `Container::make()` instantiates inside the child,
forks inherit the instance.

---

## Running

| Call                                                       | Behavior |
|------------------------------------------------------------|----------|
| `Cleanup::dispatch()`                                      | Background fork (typical). Throws if already running. |
| `Cleanup::start()`                                         | Foreground (current process). Same lock-protected lifecycle inside `resolution()`. |
| `Cleanup::status($showStats = false)`                      | `?TDInfo` — `null` if not running |
| `Cleanup::stop()`                                          | Send SIGINT to the running PID; `bool` |
| `call thread run app.threads.daemons.Cleanup -d`           | CLI background |
| `call thread run app.threads.daemons.Cleanup`              | CLI foreground |

For production, run the daemon under a supervisor (`systemd`, Docker
`restart: always`) and let `call thread run … ` run in foreground —
let the supervisor own the lifecycle. Use `-d` only for ad-hoc kicks.

---

## Cluster lock (`DaemonStore`)

The lock key is `xxh64(static::class)` — same class, same key, machine-wide.

`dispatch()` does:

```
$info = Class::status()
  ↓
if $info: throw DaemonException("Cluster process already exist [PID:...]")
else:     parent::dispatch($data)     // proceed with fork
```

`resolutionStart()` writes a `TDStatus` row:

```php
new TDStatus(
    pid:       getmypid(),
    className: static::class,
    condition: TCondition::STARTED,
    startedAt: time(),
    streamRps: $this->streamRps,
    info:      [],
);
```

`resolutionEnd()` removes it. So `status()` reflects the live state —
if it returns non-null but `posix_getpgid($status->pid)` is dead, the
key is auto-pruned and `status()` returns `null`. Crash-resilient.

`DaemonStore` is two `FileStorage` slots under
`Kernel::runnable("<dotted class>")`:

| Slot         | Holds                                                |
|--------------|------------------------------------------------------|
| `main()`     | One key — the daemon's `TDStatus` (or `TDInfo` view) |
| `threads()`  | One key per active fork — `_<pid>_` → `TStatus`      |

---

## Status & stop from outside

Anywhere in the same project (HTTP controller, CLI script, another
daemon) you can introspect:

```php
$info = Cleanup::status(showStats: true);
if ($info) {
    echo "Cleanup PID {$info->status->pid}";
    echo " started {$info->status->getStartedAt()}";
    echo " condition: {$info->status->condition->name}";
    if ($info->stats) {
        echo " RSS {$info->stats->rssMb()} MB";
    }
}

if ($info) {
    Cleanup::stop();   // SIGINT to PID; bool
}
```

`stop()` throws `DaemonException` if there is no live instance.

---

## State during a run

`ThreadDaemonStatement` exposes a few self-mutators (the daemon updates
its own record):

| Method                                         | Effect |
|------------------------------------------------|--------|
| `prepare(int $streamRps = 0)`                  | Set condition `PREPARATION` → `ACTIVE`, store `streamRps`, call `preparation()` hook |
| `setCondition(TCondition $c)`                  | Update the daemon's `condition` |
| `setInfo(array $info)`                         | Update the freeform `info` blob on `TDStatus` |
| `preparation()` (override)                     | Hook called inside `prepare()` for one-time setup |

Conditions (`TCondition` enum):

| Case          | Value |
|---------------|-------|
| `STARTED`     | 0     |
| `ACTIVE`      | 1     |
| `PREPARATION` | 2     |
| `WAITING`     | 3     |
| `CHECKING`    | 4     |
| `PASSIVE`     | 5     |

---

## Forks & fork-status

`ThreadDaemonFork` overrides `forkStart()` / `forkEnd()` to:

- write a `TStatus` for the fork into `store()->threads()` keyed
  `_<pid>_` (so the fork is observable);
- delete it on exit.

That gives you these static introspection helpers (also from outside the
process):

| Method                                                   | Returns |
|----------------------------------------------------------|---------|
| `Class::forkQty()`                                       | `int` — current fork count |
| `Class::forkList()`                                      | `int[]` — child PIDs |
| `Class::forkListInfo(bool $showStats = false)`           | `TInfo[]` — one per child, optional `ps` stats |
| `Class::forkInfo(int $pid, bool $showStats = false)`     | `?TInfo` for a single child |
| `Class::forkSetCondition(int $pid, TCondition)`          | Update a fork's condition |

Children's lifecycle hooks `preparationForkBefore()` /
`preparationForkAfter()` are open for override if you need to do more
than write a `TStatus`.

---

## `streaming()` — rate-limited fork loop

```php
final protected function streaming(
    callable $complianceCallable,
    ?callable $negationCallable = null,
): void
```

A built-in supervisor loop:

```
while (true) {
    if (forkQty() < $this->streamRps) {
        $complianceCallable();      // typically: $this->fork(...)
    } else if ($negationCallable) {
        $negationCallable();        // e.g. "log skipped", "yield"
    }
    usleep( $streamRps < 1000 ? 1e6/$streamRps : 1000 );
    pcntl_signal_dispatch();
}
```

So `$streamRps` sets the cap on **concurrent forks**, not jobs/sec:

- `streamRps = 10` → at most 10 child forks alive at once; spawn when
  one finishes (because `forkQty()` drops below 10).
- `streamRps = 0` → never spawns (use the negation branch only).

Use it when you want a constant in-flight worker count without writing
the loop yourself:

```php
public function resolution(mixed $data = null): void
{
    $this->prepare(streamRps: 50);
    $this->streaming(
        complianceCallable: function () {
            $this->fork(fn() => $this->doOne());
        },
        negationCallable: function () {
            // optional — runs when at capacity
        },
    );
}
```

---

## Signals (parent + forks)

`ThreadDaemonHandler` mirrors `ThreadProcessHandler` but walks
`forkList()` from the store (the source of truth) rather than the
in-memory `$childrenPids`:

| Signal   | Parent path                                                    | Fork path |
|----------|----------------------------------------------------------------|-----------|
| `SIGHUP` | propagate → `resolutionEnd()` → `asClose()` → exit             | `preparationForkAfter()` → `asChildClose()` → exit |
| `SIGINT` | propagate → `resolutionEnd()` → `asInterrupt()` → exit         | `preparationForkAfter()` → `asChildInterrupt()` → exit |
| `SIGTERM`| propagate → `resolutionEnd()` → `asTermination()` → exit       | `preparationForkAfter()` → `asChildTermination()` → exit |

`resolutionEnd()` is what deletes the main-store key, so a clean SIGINT
takes the daemon out of `status()` immediately.

`Class::stop()` is just SIGINT to the recorded PID, so it produces the
same path as Ctrl-C on a foreground run.

---

## Errors

- `DaemonException` — produced by `dispatch()` (already running) and
  `stop()` (not running). HTTP code `LOCKED` (423), critical log level.
- Exceptions inside `resolution()` flow through `Dispatch::run()`'s
  catch: logged, `resolutionEnd()` runs (key is removed from the store),
  daemon exits cleanly.
- Exceptions inside a fork go through `ThreadDaemonFork::fork()`'s catch
  and exit `0` — they do not kill the daemon.

---

## Examples

### Periodic check / heartbeat

```php
public function resolution(mixed $data = null): void
{
    $this->prepare();          // sets ACTIVE without streaming
    while (true) {
        try {
            $this->fork(fn() => $this->healthCheck());
        } finally {
            sleep(30);
            pcntl_signal_dispatch();
        }
    }
}

protected function healthCheck(): void
{
    // child body
}
```

### Bounded concurrency over a queue

```php
public function resolution(mixed $data = null): void
{
    $this->prepare(streamRps: 20);   // cap at 20 in-flight
    $this->streaming(function () {
        $this->fork(fn() => $this->pullAndHandle());
    });
}
```

### Operate from a controller

```php
public function start(): array
{
    return ['pid' => Cleanup::dispatch()];     // throws if running
}

public function stop(): bool
{
    return Cleanup::stop();
}

public function status(): ?array
{
    $info = Cleanup::status(showStats: true);
    return $info ? [
        'pid'       => $info->status->pid,
        'condition' => $info->status->condition->name,
        'started'   => $info->status->getStartedAt(),
        'rssMb'     => $info->stats?->rssMb(),
        'forks'     => Cleanup::forkQty(),
    ] : null;
}
```

---

## Source

- `src/Stereotype/Daemon.php`
- `src/Process/ThreadDaemon.php`, `DaemonException.php`
- `src/Process/Core/DaemonStore.php`, `Dispatch.php`, `Dispatchable.php`
- `src/Process/Traits/ThreadDaemonFork.php`,
  `ThreadDaemonHandler.php`, `ThreadDaemonStatement.php`,
  `ThreadSignalHandler.php`
- `src/Process/Entity/{TCondition,TDInfo,TDStatus,TInfo,TStatus,TStats}.php`

## See also

- [00-overview.md](00-overview.md) — `Dispatch` lifecycle and DI
- [02-process.md](02-process.md) — the unlocked, lighter forking variant
- [`../console/09-thread.md`](../console/09-thread.md) — `call thread run -d`
- [`../console/02-make.md`](../console/02-make.md) — scaffold with `-N`
- [`../configuration/02-logging.md`](../configuration/02-logging.md) — per-channel logs
