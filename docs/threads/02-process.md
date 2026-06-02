# Process — long-running forking workers

A `Process` is a long-lived `Dispatchable` that owns its own child
forks. Unlike `Job` (run-once) and `Daemon` (singleton with state),
`Process` is a general-purpose forking parent — you decide when to
fork, how many children to keep, and when to wait.

---

## Stereotype

```php
namespace App\Threads\Processes;

use Flytachi\Winter\K2\Stereotype\Process;

class QueueWorker extends Process
{
    public function __construct(
        private QueueRepository $queue,
    ) {}

    public function resolution(mixed $data = null): void
    {
        // parent loop — drains the queue, forks per batch
        while (true) {
            $batch = $this->queue->pull(size: 50);
            if (!$batch) {
                sleep(1);
                pcntl_signal_dispatch();
                continue;
            }

            foreach (array_chunk($batch, 10) as $chunk) {
                $this->fork(function () use ($chunk) {
                    foreach ($chunk as $item) {
                        $this->process($item);
                    }
                });
            }

            $this->waitAll();    // join all children before next pass
            pcntl_signal_dispatch();
        }
    }

    private function process(array $item): void
    {
        // child body
    }
}
```

`Process extends ThreadProcess extends Dispatch`. `exNamespace = 'process'`.
`ThreadFork` brings the fork primitives; `ThreadProcessHandler` brings
signal propagation to children.

DI works the same as in Job / Daemon — `Container::make()` instantiates
the parent, and forks **inherit** that instance (so injected services
are usable in children).

---

## Running

| Call                                                          | Behavior |
|---------------------------------------------------------------|----------|
| `QueueWorker::start()`                                        | Foreground |
| `QueueWorker::dispatch()`                                     | Background fork; returns PID |
| `call thread run app.threads.processes.QueueWorker`           | CLI foreground |
| `call thread run app.threads.processes.QueueWorker -d`        | CLI background |

There is **no cluster lock** on `Process` — `dispatch()` of the same
class twice will spin up two independent supervisors. If you want
"one and only one" semantics, use [`Daemon`](03-daemon.md) instead.

---

## Forking primitives (`ThreadFork`)

| Method | Use | Returns |
|--------|-----|---------|
| `fork(callable $fn): int`                | Run `$fn` in a child fork | child PID |
| `forkAnonymous(mixed $data = null): int` | Run `anonymousResolution($data)` in a child | child PID |
| `wait(int $pid, ?callable $cb = null)`   | `waitpid` one specific child | — |
| `waitAll(?callable $cb = null)`          | `waitpid` every child you've forked | — |

State on the parent:

| Property              | Default | Meaning |
|-----------------------|---------|---------|
| `$childrenPidSave`    | `true`  | Track every child PID for later `waitAll()` |
| `$childrenPids`       | `[]`    | The tracked PIDs |
| `$iAmChild`           | `false` | Flipped to `true` inside `forkStart()` so signal handlers can branch |

Each fork:

1. Sets `$this->pid = getmypid()`
2. Re-resolves the logger (`LoggerFactory::getLogger(static::class)`)
3. Rewrites `cli_set_process_title()` to include `(fork)` and the tag
   (`fork` for `fork()`, `anonymous` for `forkAnonymous()`)
4. Runs your callable, swallows any throw → logs `critical`
5. **Always** calls `exit(0)` — the fork never returns to caller code

```php
$pid = $this->fork(function () {
    // child body
});
// only the parent reaches here; child has already exit()-ed
```

### `forkAnonymous` + `anonymousResolution`

For a default callable you don't want to write out every time, override
the protected `anonymousResolution(mixed $data = null)` method and call
`forkAnonymous($data)`:

```php
public function anonymousResolution(mixed $data = null): void
{
    $this->process($data['item']);
}

// elsewhere
$this->forkAnonymous(['item' => $row]);
```

---

## Signals (parent + children)

`ThreadProcessHandler` is signal-aware of `iAmChild`:

| Signal   | Parent path                                       | Child path           |
|----------|---------------------------------------------------|----------------------|
| `SIGHUP` | propagate to children → `resolutionEnd()` → `asClose()` → exit | `asChildClose()` → exit |
| `SIGINT` | propagate to children → `resolutionEnd()` → `asInterrupt()` → exit | `asChildInterrupt()` → exit |
| `SIGTERM`| propagate to children → `resolutionEnd()` → `asTermination()` → exit | `asChildTermination()` → exit |

The parent walks `$childrenPids`, sends the same signal to each,
`waitpid()`s, then exits. The child only runs its own
`asChildXxx()` hook and exits. Override either family to log custom
messages or do final cleanup:

```php
protected function asInterrupt(): void
{
    parent::asInterrupt();        // notice "INTERRUPTED"
    $this->queue->returnInFlight();
}

protected function asChildInterrupt(): void
{
    parent::asChildInterrupt();   // notice "INTERRUPTED CHILD"
}
```

For long loops without natural syscall yields, call
`pcntl_signal_dispatch()` per iteration so signals are delivered.

---

## Errors

Inside `fork()` / `forkAnonymous()`, exceptions are caught and logged
at `critical` level; the child exits `0` regardless. The parent never
sees a child's exception.

Exceptions in the parent body (your `resolution()` loop) flow through
`Dispatch::run()`'s catch: logged, then `resolutionEnd()` runs, then
the parent exits.

---

## Examples

### Queue drain with bounded fork count

```php
public function resolution(mixed $data = null): void
{
    while (true) {
        while (count($this->childrenPids) >= 8) {
            $finished = pcntl_wait($status);
            $this->childrenPids = array_diff($this->childrenPids, [$finished]);
        }
        $batch = $this->queue->pull(1);
        if ($batch) {
            $this->fork(fn() => $this->process($batch[0]));
        } else {
            usleep(100_000);
        }
        pcntl_signal_dispatch();
    }
}
```

### Periodic supervision

```php
public function resolution(mixed $data = null): void
{
    while (true) {
        $this->fork(fn() => $this->tick());
        $this->waitAll();
        sleep(60);
        pcntl_signal_dispatch();
    }
}
```

---

## When to use Process vs Daemon

| Concern                                        | `Process`              | `Daemon`                                  |
|------------------------------------------------|------------------------|-------------------------------------------|
| One-of-a-kind running on the box?              | not enforced           | enforced via `DaemonStore` cluster lock   |
| Per-fork status tracking                       | manual (`$childrenPids`) | built-in (`forkList()`, `forkListInfo()`) |
| `Class::status()` / `Class::stop()` from outside | not provided          | provided                                  |
| `streaming()` rate-limited fork loop           | not provided           | provided                                  |
| Just a parent loop with forks                  | ✓                      | overkill                                  |

If you find yourself adding singleton checks, status persistence, or
external "stop me" hooks to a `Process`, you want [`Daemon`](03-daemon.md).

---

## Source

- `src/Stereotype/Process.php`
- `src/Process/ThreadProcess.php`
- `src/Process/Traits/ThreadFork.php`, `ThreadProcessHandler.php`,
  `ThreadSignalHandler.php`
- `src/Process/Core/Dispatch.php`, `Dispatchable.php`

## See also

- [00-overview.md](00-overview.md) — `Dispatch` lifecycle and DI
- [01-job.md](01-job.md) — simpler one-shot variant
- [03-daemon.md](03-daemon.md) — singleton with state + control
- [`../console/09-thread.md`](../console/09-thread.md) — running via `call thread`
- [`../console/02-make.md`](../console/02-make.md) — scaffold with `-P`
