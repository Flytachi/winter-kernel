# `call mapping` — route cache management

Manages the on-disk route cache. Routing is normally scanned from
controllers at boot via `Router::fromScan(Kernel::$pathRoot)`; on every
boot. For production you compile that scan into a single PHP file with
`mapping build`, and `BaseBoot` loads it with `Router::fromCache()`
instead.

---

## Synopsis

```
call mapping <command> [pattern]
```

| Command         | Purpose                                                |
|-----------------|--------------------------------------------------------|
| `build`         | Scan controllers and write the route cache file        |
| `clean`         | Delete the route cache file                            |
| `show`          | List every registered route                            |
| `show <pattern>`| List routes whose path contains the substring          |
| `<pattern>`     | Same as `show <pattern>` (any non-keyword sub is treated as a pattern) |

The cache file lives at `Router::cachePath()` (the kernel decides the
location — typically `storage/volatile/`).

---

## `call mapping build`

Walks the project, builds the full `Router`, and dumps it to disk:

```bash
call mapping build
```

```
 | [============ Mapping ============]
 |   mapping cache ............... [BUILT]
 | [i] /srv/acme/storage/volatile/router.php
 | [============ Mapping ============]
```

Run this in your image build / deploy step so production cold starts
don't pay for the controller scan.

`Router::fromCache()` is what `BaseBoot::boot()` prefers when the cache
file exists; `fromScan()` is the live fallback.

---

## `call mapping clean`

Deletes the cache file. Subsequent boots fall back to live scanning.

```bash
call mapping clean
```

```
 |   mapping cache ............... [CLEANED]
```

If the file is already missing, the badge is `[NOT FOUND]` (no error).

---

## `call mapping show [pattern]`

Prints a sorted, formatted listing of every route. If the cache file
exists, it is read from disk; otherwise a live scan is performed. The
optional pattern filters by **path substring** (leading/trailing slashes
are stripped).

```bash
call mapping show               # everything
call mapping show api/user      # routes containing "api/user"
call mapping show api           # all /api/... routes
call mapping api                # same — bare arg falls through to `show`
```

Sample output:

```
 | [============ Mapping ============]
 | [ Routes (42) ]
 |   GET     /api/users                   → App\Controllers\UserController::list
 |   POST    /api/users                   → App\Controllers\UserController::create
 |   GET     /api/users/{id}              → App\Controllers\UserController::show
 |   ...
 | [ Routes ]
 | [============ Mapping ============]
```

The method column is padded to 7 chars; handler is rendered as
`Class::method`.

---

## Examples

```bash
call mapping build
call mapping clean
call mapping show
call mapping show api
call mapping show api/user/profile
```

---

## When to use what

| Situation                                | Command           |
|------------------------------------------|-------------------|
| Production deploy                        | `mapping build`   |
| Just added/removed a controller method   | `mapping clean` (or rebuild) |
| Debugging a 404 — is the route there?    | `mapping show <fragment>` |
| Auditing the full route surface          | `mapping show`    |

In development, leaving the cache absent (`mapping clean`) keeps every
boot live — convenient if you're churning controllers.

---

## Source

- `console/Command/Mapping.php`
- Router: `src/Route/Router.php`
- Routes collector: `src/Route/Collector/`

## See also

- [04-run.md](04-run.md) — `Run` loads `Router::fromScan` at start
- [`../architecture/01-routing.md`](../architecture/01-routing.md) — annotation-based routing
- [10-di.md](10-di.md) — analogous cache management for DI
