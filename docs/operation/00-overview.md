# Operation — async results via `Future`

`Operation::async()` wraps any callable, runs it in a **child process**
(a `Thread`), and hands back a `Future`. From there you either **await**
the result or **drop** the handle for fire-and-forget execution.

Namespace: `Flytachi\Winter\K2\Unit\Operation`. The result is marshaled
back to the parent through a volatile store (`Kernel::volatile('operations')`),
so this is real OS-level process isolation, not coroutines or threads-in-memory.

```php
use Flytachi\Winter\K2\Unit\Operation\Operation;

// Await the result
$value = Operation::async(fn() => heavyJob())->return();

// Fire-and-forget — don't keep the Future
Operation::async(fn() => sendWelcomeEmail($userId));
```

---

## The three classes

| Class      | Role                                                                 |
|------------|----------------------------------------------------------------------|
| `Operation`| Static entry point. `async(callable): Future` dispatches the task.    |
| `Future`   | Handle to the running task. Await with `return()` / `get()`.         |
| `OpResult` | Immutable outcome — either a return value or a caught `Throwable`.    |

`OperationRunnable` is the internal `Runnable` that wraps your callable;
application code never touches it directly.

---

## `Operation`

```php
public static function async(callable $callback): Future
public static function store(): FileStorage
```

`$callback` is any PHP callable — closure, named function, or invokable
object. The child process is spawned immediately (inside `Future`'s
constructor), so the work starts the moment `async()` returns.

`store()` exposes the shared `FileStorage` used to pass results between
parent and child. You rarely need it directly.

---

## `Future`

```php
public function return(bool $isThrow = true): mixed   // raw value
public function get(): OpResult                        // full outcome
public function join(): void                           // wait, no result
```

| Method            | Blocks? | Returns                | Notes |
|-------------------|---------|------------------------|-------|
| `return()`        | yes     | the callback's value   | Re-throws the child's exception when `$isThrow = true` (default). |
| `return(false)`   | yes     | the callback's value   | Swallows the exception; returns `null` if the task threw. |
| `get()`           | yes     | `OpResult<TResult>`    | Inspect success/failure yourself via `getThrowable()`. |
| `join()`          | yes     | `void`                 | Waits for the process to finish without reading a result. |

`get()` waits for the child, then reads the stored result with up to 3
retries (1 ms apart). If nothing is found it throws
`Error('Operation result not found')`.

---

## `OpResult`

```php
public function getResult(): mixed         // the returned value (null on failure)
public function getThrowable(): ?Throwable // the caught exception, or null
```

```php
$opResult = $future->get();

if ($opResult->getThrowable() !== null) {
    log_error($opResult->getThrowable()->getMessage());
} else {
    $value = $opResult->getResult();
}
```

---

## Await vs fire-and-forget

The mode is decided by **whether you keep the `Future`**, not by a flag.

```php
// AWAIT — block until the background task returns
$report = Operation::async(fn() => buildMonthlyReport($month))->return();

// FIRE-AND-FORGET — let the Future go out of scope
public function store(Request $request): Response
{
    Operation::async(fn() => sendReceipt($request->user));
    return new Response('queued');   // Future destructed here → result discarded
}
```

When a `Future` is destroyed without `get()` / `return()`, its destructor
deletes the "pending" marker in the store. The child sees the marker is
gone and **does not write its result back** — no orphaned data accumulates.

---

## How the result crosses the process boundary

```
Operation::async($cb)
  ↓
new OperationRunnable($cb)            ← id = "op_" + 16 random chars
  ↓
new Future(id, Thread)
  ├── store->write(id, 'pending')     ← parent marks the slot
  └── Thread::start()                 ← fork
        ↓ (inside the child)
      $result = $cb()                 ← YOUR CODE, isolated process
        ↓
      if store->read(id) === 'pending'
          store->write(id, OpResult)  ← only if parent still waiting
  ↓ (back in the parent)
$future->get() → store->read(id) → store->del(id)
```

---

## Caveats — it's a separate process

- The callback runs in a **forked child**. Mutations to objects, static
  state, or in-memory caches inside the callback are **not** visible to
  the parent — only the returned value comes back.
- The return value must survive storage in the volatile store. Return
  plain serializable data; don't return live resources (open DB handles,
  sockets, stream resources).
- Closures capture by value at fork time. A captured DB connection may not
  be usable in the child — open what you need **inside** the callback.
- `return()` / `get()` are **blocking**. For genuinely parallel work,
  dispatch several operations first, then await them:

```php
$a = Operation::async(fn() => fetchA());
$b = Operation::async(fn() => fetchB());

// both already running in parallel
[$ra, $rb] = [$a->return(), $b->return()];
```

---

## Error handling

If the callback throws, the exception is caught in the child and carried
back inside the `OpResult`:

- `return()` (default) re-throws it in the parent — handle it with a
  normal `try/catch` around the await.
- `return(false)` returns `null` instead of throwing.
- `get()` never throws the child's exception — inspect `getThrowable()`.

```php
try {
    $value = Operation::async(fn() => riskyJob())->return();
} catch (\Throwable $e) {
    // the exception thrown inside the child surfaces here
}
```

---

## Source

- `src/Unit/Operation/Operation.php` — `async()`, `store()`
- `src/Unit/Operation/Future.php` — `return()`, `get()`, `join()`, destructor
- `src/Unit/Operation/OpResult.php` — `getResult()`, `getThrowable()`
- `src/Unit/Operation/OperationRunnable.php` — internal `Runnable` wrapper

## See also

- [`../threads/00-overview.md`](../threads/00-overview.md) — the `Thread` /
  `Dispatch` model `Operation` is built on
- [`../threads/01-job.md`](../threads/01-job.md) — stereotype-based
  background tasks when you want a named, DI-managed class instead of a closure
