# Build and caches

`#[Async]` needs generated code — a proxy class per annotated service. The cache
attributes (`#[Cacheable]`, `#[CachePut]`, `#[CacheEvict]`) work the same way and
share it: a class gets one proxy whatever features it uses. This page covers where
that code comes from, where it lives, and what the build step is actually for.

Nothing here applies if you only use `Executors` directly: the primitive
generates nothing and caches nothing.

---

## What gets produced

Two artefacts, both derived from the same project scan and both living in
volatile storage next to the DI cache:

| Path | Contents |
|---|---|
| `<volatile>/proxy.php` | list of proxied classes, and the features it was built for |
| `<volatile>/proxy/*.php` | the generated proxy classes |

Releases before the shared generator used `async.php` and `async/`; `call di build`
and `call di clean` remove them.

`<volatile>` is `Kernel::$pathStorageVolatile`. By default that is
`sys_get_temp_dir() . '/flytachi.winter.volatile.<project>'`; with
`Kernel::init(isTmpVolatile: false)` it becomes `storage/volatile`. The proxies
follow that setting automatically — they are the same kind of artefact as
`di.php`, so they obey the same durability policy.

Both are **regenerable**. If the directory is wiped, the next boot rebuilds what
it needs; writes are atomic, so several Swoole workers rebuilding at once cannot
corrupt a file.

---

## When generation happens

```
DEBUG=true    → no caches at all; a proxy is regenerated whenever its source file is newer
DEBUG=false   → generated on first boot if missing, then only checked for existence
                (and for the features it was built for — enabling #[EnableCaching]
                regenerates an async-only proxy)
```

Editing a service and reloading is enough in development. In production nothing
is regenerated after the first boot unless the files are gone.

The class list is cached separately for a reason: **finding** intercepted methods
means reflecting every method of every class in the project, which costs about
three times as much as the whole DI collector and would be paid on every boot —
under FPM, on every request. With the list cached, later boots do a plain array
lookup.

| Per boot, 300-class project | |
|---|---|
| DI collector alone | 0.26 ms |
| + async, list cached | 0.30 ms |
| + async, discovering | 0.59 ms |

Memory overhead of the layer at boot: about 2 KB.

---

## `call di build`

One scan produces everything the container needs:

```
$ php call di build

 |  di cache ........................ [BUILT (247 classes)]
 |  caches .......................... [DECLARED (products, stock)]
 |  proxies ......................... [BUILT (6 classes, 14 methods)]
 |  proxy bypass .................... [NONE]
```

The DI class list and the proxies come from the same filesystem walk on purpose
— two separate commands would leave a window where one is stale.

**The build is primarily a contract check.** With volatile storage in `/tmp` the
generated files do not survive into a container image anyway, so the value is
not a warmer start: it is that an invalid `#[Async]` or cache method — or a cache
attribute naming a cache no `CacheConfigurer` declares — fails here rather than on
the first request in production. The `caches` line appears only with
`#[EnableCaching]`.

```
 |  di cache ........................ [BUILT (248 classes)]
 |  proxies ......................... [FAILED]
 | [!] App\Services\BrokenService: the class is final and cannot be extended.
      Drop "final" from the class, or move the #[Async] method to a non-final service.
```

**A failed build exits with code 1** — the only command in the console that
returns a non-zero status, added precisely so CI notices. Note that the DI cache
still reports `BUILT`: the class list is written before collectors run, and the
output says so honestly rather than pretending the whole step failed.

---

## `call di clean`

Removes `di.php`, `proxy.php` and every generated proxy:

```
 |  di cache ........................ [CLEANED]
 |  proxies ......................... [CLEANED (6 files)]
```

Worth running after deleting or renaming a service — a stale proxy for a class
that no longer exists is harmless but confusing. `build` clears them itself
before regenerating.

---

## `call di proxies`

Lists every intercepted method in the project, the feature it belongs to, and
whether its proxy exists (`call di async` is kept as an alias):

```
$ php call di proxies

 | [ Proxied methods (2 classes) ]
 |  App\Services\NotificationService ......... [BUILT]
 |      track() → void  [async]
 |      send() → Flytachi\Winter\Kernel\Concurrent\Future  [async]
 |  App\Services\ProductService .............. [PENDING]
 |      find() → ?App\Entity\Product  [cache]
```

`PENDING` means the proxy has not been generated yet; it will be on first use.
Accepts an optional case-insensitive FQCN filter: `call di proxies Notification`.

---

## The bypass warning

Every build scans your sources for services built with `new` instead of resolved
from the container — the trap described in [03-async.md](03-async.md#the-new-trap):

```
 |  proxy bypass .................... [2 FOUND]
 | [!] main/Http/AuthController.php:16 — new App\Services\NotificationService()
      bypasses the proxy, so its attributes do nothing; inject it instead
 | [!] main/Http/OrderController.php:13 — new App\Services\ProductService()
      bypasses the proxy, so its attributes do nothing; inject it instead
```

It resolves names through each file's `namespace` and `use` block, so imported,
aliased, grouped and fully qualified forms are all recognised.

**It is a warning, never a build failure**, because the scan reads source text
and cannot be exhaustive:

- dynamic construction — `new $class`, factories, names from configuration — is
  invisible;
- `vendor/` and test directories are skipped, since constructing a service
  directly is usually what a test wants;
- `new self()` / `new static()` inside the service are not bypasses.

A clean report is therefore not proof of correctness — but what it does find is
almost always a real mistake. Output is capped at 20 entries, with the remainder
summarised.

---

## Deployment

```bash
php call mapping build     # route cache
php call di build          # DI cache + proxies + contract check
```

Run both in CI so a broken `#[Async]` or cache contract fails the pipeline. If your
volatile storage is project-local (`isTmpVolatile: false`) the artefacts also
ship with the image and the first request skips generation entirely.

Nothing breaks if you skip the build: the first boot generates what it needs,
provided the runtime user can write to volatile storage — the same requirement
`di.php` and `mapping.php` already have.

---

## See also

- [03-async.md](03-async.md) — the contract this step verifies
- [`console/10-di.md`](../console/10-di.md) — the rest of the `call di` command
- [`configuration/01-kernel.md`](../configuration/01-kernel.md) — `isTmpVolatile` and storage paths
