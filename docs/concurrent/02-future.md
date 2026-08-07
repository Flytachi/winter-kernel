# Future and CompletableFuture

A background task produces a value at some point after the call that started it
has already returned. A **`Future`** is the handle on that value: you hold it,
and when you finally need the result you ask for it.

```php
$future = Executors::common()->submit($api->fetch(...), $id);

// …other work, which is the entire point…

$value = $future->get();
```

`Future` is the read side, `CompletableFuture` the implementation that also
exposes the write side. Application code normally only sees the interface;
`CompletableFuture` becomes relevant when writing an `#[Async]` method body or
composing results by hand.

Both are runtime-agnostic. The executor that created a future hands it a waiting
strategy, so `get()` suspends a coroutine under Swoole and runs the task inline
under FPM — without the future knowing which happened.

---

## `Future`

```php
use Flytachi\Winter\Kernel\Concurrent\Future;
```

### `get(?float $timeout = null): mixed`

Waits for the task and returns its value.

```php
$value = $future->get();          // wait as long as it takes
$value = $future->get(2.5);       // …but no longer than 2.5 s
```

| Outcome | Result |
|---|---|
| Task returned | the value |
| Task threw | throws `ExecutionException`, original in `getPrevious()` |
| Task was cancelled | throws `CancellationException` |
| Timeout elapsed | throws `TimeoutException` |

**A timeout bounds the wait, not the task.** The work keeps going, and a later
`get()` on the same future still returns the result:

```php
try {
    $value = $future->get(0.05);
} catch (TimeoutException) {
    $logger->info('slow, moving on');
}

$value = $future->get();          // still works, still correct
```

### `isDone(): bool`

True once the task has completed, failed or been cancelled — the three are not
distinguished here. Use `isCompletedExceptionally()` or `isCancelled()` when you
need to tell them apart.

### `isCancelled(): bool`

True if the task was cancelled before it produced a result.

### `cancel(bool $mayInterruptIfRunning = false): bool`

Attempts to cancel; returns `true` only if this call is what cancelled it. With
`$mayInterruptIfRunning` the backend also tries to interrupt work already in
progress — under Swoole that means cancelling the coroutine.

---

## `CompletableFuture`

```php
use Flytachi\Winter\Kernel\Concurrent\CompletableFuture;
```

Everything `Future` has, plus the ability to settle it yourself.

### Factories

```php
CompletableFuture::completedFuture($value);        // already successful
CompletableFuture::failedFuture($throwable);       // already failed
```

`completedFuture()` is what the body of a `Future`-returning `#[Async]` method
returns — see [03-async.md](03-async.md).

```php
CompletableFuture::supplyAsync(fn() => $api->fetch($id));      // submit()
CompletableFuture::runAsync(fn() => $mailer->send($to));       // result discarded
```

Both accept an optional executor and default to `Executors::common()`.

### `allOf(Future ...$futures): Future`

Completes when every given future has completed. The value is always `null`;
you read the individual futures for results.

```php
$a = Executors::common()->submit(fn() => $billing->fetch($id));
$b = Executors::common()->submit(fn() => $crm->fetch($id));

CompletableFuture::allOf($a, $b)->get();

$data = ['billing' => $a->get(), 'crm' => $b->get()];
```

If any input fails or is cancelled, the returned future fails with that same
throwable.

> For a batch you are building anyway, `ExecutorService::invokeAll()` is more
> direct — it submits and waits in one call. `allOf()` is for futures you
> already hold, possibly from different sources.

### Settling manually

```php
$future = new CompletableFuture();

$future->complete($value);                  // → true, or false if already settled
$future->completeExceptionally($throwable); // → same
```

Both return `false` when the future was already settled, which makes them safe
to call from a race without checking first.

### `whenComplete(\Closure $action): self`

Registers a callback fired once the future settles, receiving `(mixed $value,
?Throwable $error)` — exactly one of them is non-null. If the future has already
settled, the callback runs immediately.

```php
$future->whenComplete(function (mixed $value, ?\Throwable $e) use ($logger): void {
    $e === null
        ? $logger->info('done')
        : $logger->error($e->getMessage());
});
```

> Unlike Java this returns the **same** instance rather than a new stage. The
> composition pipeline (`thenApply`, `thenCompose`, …) is deliberately not
> implemented — it buys little in a language without a fluent async ecosystem,
> and it would double the surface to maintain.

### Inspecting the outcome

```php
$future->isCompletedExceptionally();   // failed?
$future->failure();                    // the throwable, or null
$future->join();                       // get() with no timeout
```

`failure()` is the way to look at an error **without** it being thrown at you —
useful when processing a batch where some failures are expected:

```php
foreach ($results as $future) {
    if ($error = $future->failure()) {
        $logger->warning('partial failure: ' . $error->getMessage());
        continue;
    }
    $ok[] = $future->get();
}
```

---

## Errors in background work

Where a failure surfaces depends on how the task was started:

| Started with | A throwable |
|---|---|
| `submit()` | is stored on the future, re-thrown wrapped in `ExecutionException` by `get()` |
| `execute()` | is **logged** — nobody holds a handle, so silence would lose it |
| `invokeAll()` | is stored on the individual future; the batch itself does not throw |

`ExecutionException` always wraps the original:

```php
try {
    $future->get();
} catch (ExecutionException $e) {
    $original = $e->getPrevious();      // the real exception from the task body
}
```

A task that is never awaited and never logged would be a black hole, so the
deferred backend logs failures of futures it had to run during the drain — that
is, ones nobody ever asked for.

---

## See also

- [01-executors.md](01-executors.md) — where futures come from
- [03-async.md](03-async.md) — `#[Async]` methods return a `Future`
