# `#[Async]`

With `Executors` the decision to go asynchronous is made at the **call site** —
every place that calls the service has to remember to wrap it. When a service is
asynchronous *by nature*, that repetition is noise, and one forgotten wrap is a
silent regression.

`#[Async]` moves the decision into the declaration:

```php
#[Singleton]
class NotificationService
{
    #[Async]
    public function track(int $userId, string $event): void
    {
        $this->mixpanel->push($userId, $event);
    }
}
```

```php
class AuthController extends Controller
{
    #[Autowired]
    protected NotificationService $notifications;

    #[PostMapping('/register')]
    public function register(): ResponseEntity
    {
        $user = $this->users->create(…);
        $this->notifications->track($user->id, 'signup');   // returns immediately

        return ResponseEntity::ok($user);
    }
}
```

The controller knows nothing. Nothing is registered by hand. This is Spring's
`@Async`, and — as there — it comes with rules, because PHP has no way to make a
method asynchronous without generating code around it.

---

## How it works

At scan time the kernel finds every class with `#[Async]` methods and generates
a **subclass** that overrides them:

```php
final class NotificationService__Async extends NotificationService implements ProxyInterface
{
    public static function proxyTarget(): string
    {
        return NotificationService::class;
    }

    public function track(int $userId, string $event): void
    {
        AsyncSupport::execute(Executors::common(), fn() => parent::track($userId, $event));
    }
}
```

The container binding is then swapped — `NotificationService` resolves to the
generated class — while the DI lifetime is preserved: a `#[Singleton]` service
stays a singleton, only its concrete class changes.

Generated files live in volatile storage and are produced on first boot or by
`call di build`; see [04-build.md](04-build.md).

Because the proxy **extends** the original, `instanceof NotificationService`
still holds and every type hint keeps working.

---

## The contract

A method carrying `#[Async]` must satisfy five rules. All of them are checked
when proxies are generated — a violation fails the build, never a request.

### 1. The class is not `final`, the method is not `final` or `static`

The proxy is a subclass. A `final` class cannot be extended, a `final` method
cannot be overridden, and a `static` call names the class at the call site so no
subclass ever participates:

```php
NotificationService::track($id, 'signup');   // resolved by name → original class
```

`protected` methods **are** allowed — PHP dispatches them virtually, so an
internal asynchronous helper works and a self-call reaches the override.

`private` methods are not: PHP resolves them statically inside their own class,
so no subclass can intercept. Make the method `protected`.

### 2. The return type is `Future` or `void`

An overriding method must be compatible with the declared type, so the proxy can
only return what the signature already promises. This is the same rule Spring
has, for the same reason.

### 3. A `Future`-returning body returns a completed future

```php
#[Async]
public function send(int $userId): Future
{
    $result = $this->mailer->send($userId);

    return CompletableFuture::completedFuture($result);
}
```

The proxy runs this body in the background and immediately returns a *pending*
future; when the body finishes, the value inside its completed future is
unwrapped into the outer one. The caller sees a plain `Future` carrying
`$result`.

### 4. No by-reference parameters

An asynchronous call returns before the body runs, so writes to a `&$param`
could never be observed. Return the value instead.

### 5. The object comes from the DI container

This is the rule with teeth — see below.

---

## The `new` trap

`#[Async]` is metadata; on its own it does nothing. Only the container knows
about the substitution, and `new` goes straight past it:

```php
// ❌ runs synchronously — the request waits
$service = new NotificationService();
$service->track($user->id, 'signup');

// ✅ container hands out the proxy
#[Autowired]
protected NotificationService $notifications;
```

Measured on the same class and the same call:

```
from the container:  returned in 0.4 ms, result after 102 ms
via new:             returned in 101 ms  ← blocked
```

Nothing distinguishes the two in code — same declared type, `instanceof Future`
true in both, `get()` returns the same value. The only tell is that the `new`
version's future is already `isDone()`, which nobody checks.

`void` methods are worse still: with no return value there is nothing to
inspect at all.

Two mitigations:

- **`call di build` warns about it.** The build scans your sources for `new X()`
  where `X` has `#[Async]` methods and reports file and line. It is textual and
  therefore not exhaustive — see [04-build.md](04-build.md).
- **Prefer returning `Future` over `void`**, even when the result is not needed.
  `$future = $svc->track(...)` at least reads as asynchronous.

---

## Self-invocation works

In Spring, one method of a bean calling another annotated method of the same
bean bypasses the proxy — the famous `@Async` gotcha. The cause is that Spring's
proxy and the target bean are two different objects, and `this` inside the body
is the target.

Here there is only one object: the container creates the proxy itself, so `this`
in an inherited method **is** the proxy, and PHP dispatches virtually.

```php
class NotificationService
{
    #[Async]
    public function track(int $userId, string $event): Future { … }

    public function trackBatch(array $userIds): void
    {
        foreach ($userIds as $id) {
            $this->track($id, 'batch');    // goes through the override
        }
    }
}
```

There is no recursion risk: the override calls `parent::track()`, which is
non-virtual by definition.

---

## What a task does and does not inherit

An `#[Async]` method runs in a fresh execution context. Under Swoole that is
literally a new coroutine context.

**Inherited:** nothing beyond its arguments and the object's own state.

**Not inherited:** request headers, locale, repository query state, logging
correlation fields. A repository configured in the caller will arrive empty —
build it inside the method.

Pass everything the task needs explicitly:

```php
#[Async]
public function sendWelcome(int $userId, string $locale): Future   // ← locale is an argument
```

The one thing that *is* handled for you is the database connection: the task
borrows its own from the PPA pool and returns it automatically when it ends.

---

## Choosing an executor

```php
#[Async(executor: 'reports')]
public function build(int $month): Future { … }
```

The argument is a container id resolved at call time. Omitted, the method uses
`Executors::common()`.

---

## When not to use it

`#[Async]` needs the container. Where there is no container there is no
substitution, and the primitive is the honest answer:

| Situation | Use |
|---|---|
| `final` class or `final` method | `Executors::common()` |
| `static` method | `Executors::common()` inside the method body |
| object built with `new` | `Executors::common()` |
| `private` method | make it `protected`, or call the executor directly |
| one-off background work | `Executors::common()` |

Nothing is lost by doing so — `#[Async]` is sugar over exactly that call.

---

## See also

- [01-executors.md](01-executors.md) — the primitive underneath
- [02-future.md](02-future.md) — `CompletableFuture::completedFuture()`
- [04-build.md](04-build.md) — generation, caches, the bypass warning
- [`configuration/07-di.md`](../configuration/07-di.md) — container lifetimes the proxy preserves
