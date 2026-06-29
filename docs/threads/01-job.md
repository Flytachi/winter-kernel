# Job — fire-and-forget background tasks

A `Job` is the simplest `Dispatchable` — runs `resolution()` once,
no forking inside the body, no state, no cluster lock. Use it for
one-shot work that must outlive the originating request.

---

## Stereotype

```php
namespace App\Threads\Jobs;

use Flytachi\Winter\K2\Stereotype\Job;
use Flytachi\Winter\DI\Attribute\Autowired;

class SendInvoice extends Job
{
    #[Autowired]
    private MailService $mail;

    public function __construct(
        private InvoiceRepository $invoices,
    ) {}

    public function resolution(mixed $data = null): void
    {
        $invoice = $this->invoices->find($data['orderId']);
        $this->mail->send($invoice);
    }
}
```

`Job extends ThreadJob extends Dispatch` — three layers, but everything
you implement lives in `resolution()`. `exNamespace` is hardcoded to
`'job'`.

DI is real: both constructor injection and `#[Autowired]` resolve
when `Container::make()` instantiates the class inside the child.

---

## Running

| Call                                                              | Behavior |
|-------------------------------------------------------------------|----------|
| `SendInvoice::start(['orderId' => 42])`                           | Foreground — blocks the caller |
| `SendInvoice::dispatch(['orderId' => 42])`                        | Background fork; returns child PID |
| `call thread app.threads.jobs.SendInvoice`                        | CLI foreground |
| `call thread app.threads.jobs.SendInvoice -d`                     | CLI background |

`$data` is whatever serializable payload the job needs. It is marshaled
to the child through `DispatchStore` (see
[00-overview.md](00-overview.md#passing-data-into-a-thread-dispatchstore)).

---

## Lifecycle

```
Dispatch::dispatch($data)
  ↓
Container::make(static::class)          ← fresh DI instance
  ↓
DispatchStore::push($key, $data)        ← if $data non-empty
  ↓
Thread::start(['storeKey' => $key])     ← fork
  ↓ (inside the child)
Dispatch::run($args)
  ├── resolutionStart()                 ← logger + signal handler
  ├── resolution($data)                 ← YOUR CODE
  └── resolutionEnd()                   ← no-op for Job
```

`ThreadJob::resolutionEnd()` is `final` and empty — Jobs don't need
post-work cleanup. If you need it, put it inside `resolution()`'s own
`try/finally`.

---

## Signals

`ThreadJobHandler` wires `SIGHUP` / `SIGINT` / `SIGTERM` to immediate
exit hooks. Each first calls `resolutionEnd()`, then your overridable
hook, then `exit()`:

| Signal   | Internal handler   | Override          | Default log         |
|----------|--------------------|-------------------|---------------------|
| `SIGHUP` | `signClose()`      | `asClose()`       | `notice "CLOSE"`    |
| `SIGINT` | `signInterrupt()`  | `asInterrupt()`   | `notice "INTERRUPTED"` |
| `SIGTERM`| `signTermination()`| `asTermination()` | `warning "TERMINATION"` |

For short jobs you usually don't override anything. For long-running
`resolution()` bodies that loop over work, call
`pcntl_signal_dispatch()` once per iteration so signals are processed
between units of work.

```php
public function resolution(mixed $data = null): void
{
    foreach ($this->invoices->pending() as $invoice) {
        $this->mail->send($invoice);
        pcntl_signal_dispatch();    // graceful Ctrl-C between sends
    }
}
```

---

## Errors

Exceptions in `resolution()` are caught by `Dispatch::run()`, logged
via the resolved logger, and the child exits cleanly (no zombie, no
re-throw to the parent). `DEBUG=true` appends the stack trace to the
log entry; otherwise just the message.

If the job needs retry / DLQ semantics, build them on top — the kernel
does not retry failed jobs automatically.

---

## Examples

```php
// from an HTTP controller — don't block the response
SendInvoice::dispatch(['orderId' => $order->id]);

// from another job (chained foreground)
RebuildSearchIndex::start();

// from CLI for ad-hoc execution
// call thread app.threads.jobs.SendInvoice -d
```

---

## When to use Job vs Process vs Daemon

| Need                                      | Stereotype | Why                                  |
|-------------------------------------------|------------|--------------------------------------|
| One unit of work, then exit               | `Job`      | No state, no fork bookkeeping        |
| Long-running worker that forks per task   | `Process`  | `ThreadFork` + child signal handling |
| Long-running singleton + state + control  | `Daemon`   | `status()` / `stop()` + cluster lock |

---

## Source

- `src/Stereotype/Job.php`
- `src/Process/ThreadJob.php`
- `src/Process/Core/Dispatch.php`, `Dispatchable.php`, `DispatchStore.php`
- `src/Process/Traits/ThreadJobHandler.php`, `ThreadSignalHandler.php`

## See also

- [00-overview.md](00-overview.md) — `Dispatch` lifecycle, DI, data passing
- [02-process.md](02-process.md) — forking workers
- [`../console/09-thread.md`](../console/09-thread.md) — `call thread <class>`
- [`../console/02-make.md`](../console/02-make.md) — `call make .X -J` scaffold
