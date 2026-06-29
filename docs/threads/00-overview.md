# Winter Threads — Overview

The Threads unit covers everything the kernel does outside the HTTP
request cycle: one-shot background jobs, long-running worker pools,
singleton daemons, and WebSocket servers.

All four thread types share a single hierarchy:

```
Runnable                     (winter-thread)
   ↑
Dispatchable                 (src/Process/Core/Dispatchable.php)
   ↑
Dispatch  ─── abstract base for every thread type
   ↑
   ├── ThreadJob          ←  Stereotype\Job
   ├── ThreadProcess      ←  Stereotype\Process
   ├── ThreadDaemon       ←  Stereotype\Daemon
   └── ThreadWebSocket    ←  Stereotype\WebSocket
```

The four `Stereotype\*` classes are paper-thin aliases — your code
extends one of them, the framework owns everything else.

---

## Stereotype map

| Stereotype  | Internal class    | `exNamespace` | Forks?  | Singleton? | Use case                          |
|-------------|-------------------|---------------|---------|------------|-----------------------------------|
| `Job`       | `ThreadJob`       | `job`         | no      | no         | One-shot fire-and-forget work     |
| `Process`   | `ThreadProcess`   | `process`     | yes     | no         | Long-lived worker pool            |
| `Daemon`    | `ThreadDaemon`    | `daemon`      | yes     | yes        | Single supervised long-running service |
| `WebSocket` | `ThreadWebSocket` | `web-socket`  | no      | no         | TCP WebSocket server              |

`exNamespace` shows up in `ps` via `cli_set_process_title()`, so you can
spot Winter processes at a glance:

```
Winter daemon -> runnable App.Daemons.Cleanup
Winter job(fork) -> fork App.Jobs.SendInvoice
```

---

## Entry points

Every `Dispatchable` exposes two static entry points (defined on
`Dispatch`, made `final` on each thread type):

| Method                       | Behavior                                              |
|------------------------------|-------------------------------------------------------|
| `Class::start($data = null)` | Foreground — runs in the **current** process, blocks  |
| `Class::dispatch($data = null)` | Background — forks via `Thread::start()`, returns child PID |

`Daemon` additionally exposes:

| Method                          | Behavior                                           |
|---------------------------------|----------------------------------------------------|
| `Class::status(bool $showStats=false)` | `?TDInfo` — current PID, condition, optional `ps` stats |
| `Class::stop()`                 | `bool` — send SIGINT to the running instance       |

From the CLI:

```bash
call thread app.threads.jobs.SendInvoice            # foreground
call thread app.threads.jobs.SendInvoice -d         # background
call thread list                                    # discover Dispatchable classes
call thread daemons                                 # daemons with live status
```

See [`../console/09-thread.md`](../console/09-thread.md).

---

## Lifecycle inside `Dispatch::run()`

```
Dispatch::dispatch($data) / ::start($data)
  ↓
Container::make(static::class)        ← DI: constructor injection +
                                         #[Autowired] both work here
  ↓
DispatchStore::push($key, $data)      ← only if $data is non-empty
  ↓
Thread::start(['storeKey' => $key])   ← fork (or run in-place for ::start)
  ↓ (child / current process)
Dispatch::run($args)
  ├── resolutionStart()               ← logger + signal handler
  ├── resolution($data)               ← YOUR CODE
  └── resolutionEnd()                 ← cleanup hook (per-stereotype)
```

`run()` wraps `resolution()` in `try/catch/finally`; uncaught throws are
logged through the resolved logger, and the child exits cleanly.

---

## DI in threads

`Dispatch::dispatch()` / `Dispatch::start()` instantiate the class via
`Container::getInstance()->make(static::class)`. That means **both
constructor injection and `#[Autowired]` properties work** the same way
as in controllers / services / console commands:

```php
use Flytachi\Winter\K2\Stereotype\Job;
use Flytachi\Winter\DI\Attribute\Autowired;

class SendInvoice extends Job
{
    #[Autowired]
    private MailService $mail;

    public function __construct(
        private InvoiceRepository $invoices,   // also injected
    ) {}

    public function resolution(mixed $data = null): void
    {
        $invoice = $this->invoices->find($data['id']);
        $this->mail->send($invoice);
    }
}
```

The container is re-resolved **inside the child process** (after fork),
so dependencies are fresh — DB handles, log channels, file streams are
not inherited from the parent.

---

## Passing data into a thread (`DispatchStore`)

Forked PHP processes don't share heap. To get data into the child,
`Dispatch` marshals `$data` through `DispatchStore`:

```
parent: Class::dispatch(['orderId' => 42])
   ↓
DispatchStore::push("cache-abc123", ['orderId' => 42])     ← Kernel::volatile('dispatcher')
   ↓
Thread::start(arguments: ['storeKey' => 'cache-abc123'])   ← fork
   ↓
child:   $data = DispatchStore::pop("cache-abc123")        ← read + delete
   ↓
resolution($data)
```

`pop()` is destructive — the key is removed after the read, so the
side-channel file is short-lived. For `::start()` (foreground) the same
mechanism is used; the data round-trip just happens in the same process.

Pass anything serializable — arrays, scalars, DTOs. Don't pass live
resources (connections, file handles), they don't survive the round trip.

---

## Logger

Each child gets its own logger in `resolutionStart()`:

```php
$this->logger = LoggerFactory::getLogger(static::class);
```

The class name is the channel, so per-thread logs sort naturally by
`App\Jobs\SendInvoice`, `App\Daemons\Cleanup`, etc. Configure log sinks
via the `LOGGER_*` env vars — see
[`../configuration/02-logging.md`](../configuration/02-logging.md).

---

## Signal handling

`ThreadSignalHandler` (mixed into Job / Process / Daemon / WebSocket)
wires three POSIX signals to overridable hooks. Async signals are
enabled (`pcntl_async_signals(true)`):

| Signal   | Internal handler   | Override                  | Default log |
|----------|--------------------|---------------------------|-------------|
| `SIGHUP` | `signClose()`      | `asClose()`               | notice CLOSE |
| `SIGINT` | `signInterrupt()`  | `asInterrupt()`           | notice INTERRUPTED |
| `SIGTERM`| `signTermination()`| `asTermination()`         | warning TERMINATION |

`Process` and `Daemon` additionally propagate the signal to their child
forks (`posix_kill($childPid, $sig)` + `pcntl_waitpid`) and expose a
`asChildXxx()` family — useful when the parent and children need
different shutdown logic (e.g. the parent flushes a queue, the child
just exits).

For event loops that don't naturally `read()`/`select()`, call
`pcntl_signal_dispatch()` periodically so signals are delivered while
busy.

---

## Process tagging (`ps` visibility)

`cli_set_process_title()` is called by `Dispatch` and again on every
fork (`ThreadFork::forkStart()`), producing predictable patterns:

```
Winter <namespace> -> <tag> <exName>
Winter <namespace>(fork) -> fork <exName>
Winter <namespace>(fork) -> anonymous <exName>
```

| Field          | Default              | Override via                |
|----------------|----------------------|-----------------------------|
| `exNamespace`  | `dispatch`           | per stereotype (`job`, `daemon`, `process`, `web-socket`) |
| `exTag`        | `runnable`           | replaced inside forks       |
| `exName`       | `null`               | set in subclass property    |

---

## Choosing a stereotype

```
Need:                                          Stereotype:
─────────────────────────────────────          ─────────────
One unit of work, then exit                    Job
Long-running worker that forks per task        Process
Long-running singleton + state + control       Daemon
Live WebSocket server                          WebSocket
```

| Concern                              | Job | Process | Daemon | WebSocket |
|--------------------------------------|:---:|:-------:|:------:|:---------:|
| Forks children                       |     | ✓       | ✓      |           |
| Single-instance lock (`status()`)    |     |         | ✓      |           |
| State persistence (`DaemonStore`)    |     |         | ✓      |           |
| Rate-limited stream (`streaming()`)  |     |         | ✓      |           |
| Built-in protocol loop               |     |         |        | ✓         |

---

## Per-type pages

| Page                | Stereotype  | When to read it                          |
|---------------------|-------------|------------------------------------------|
| [01-job.md](01-job.md)             | `Job`       | The simplest case — start here  |
| [02-process.md](02-process.md)     | `Process`   | Forking workers + signal propagation |
| [03-daemon.md](03-daemon.md)       | `Daemon`    | Singletons, status, streaming    |
| [04-websocket.md](04-websocket.md) | `WebSocket` | TCP WebSocket server             |

---

## Source

- Contracts: `src/Process/Core/Dispatchable.php`, `Dispatch.php`
- Stores: `DispatchStore.php`, `DaemonStore.php`
- Stereotypes: `src/Stereotype/{Job,Process,Daemon,WebSocket}.php`
- Internal classes: `src/Process/Thread{Job,Process,Daemon}.php`,
  `src/Process/Socket/Web/ThreadWebSocket.php`
- Traits: `src/Process/Traits/Thread*Handler.php`, `ThreadFork.php`,
  `ThreadDaemonFork.php`, `ThreadDaemonStatement.php`, `ThreadSignalHandler.php`

## See also

- [`../console/09-thread.md`](../console/09-thread.md) — `call thread <class>` / `list` / `daemons`
- [`../console/02-make.md`](../console/02-make.md) — scaffolding (`-J`, `-P`, `-N`, `-W`)
- [`../configuration/02-logging.md`](../configuration/02-logging.md) — log channels
