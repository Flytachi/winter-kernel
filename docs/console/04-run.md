# `call run` — start the HTTP server

Boots an HTTP server in front of the kernel's `Router`. Two modes:

- **Swoole** (default) — production-grade, coroutine-based, long-lived.
- **PHP built-in dev server** (`call run dev`) — single-threaded,
  zero-config, for local hacking only.

---

## Synopsis

```
call run                 # Swoole HTTP server (default)
call run dev             # PHP built-in dev server
```

Constants:
- `HOST` default — `0.0.0.0`
- `PORT` default — `8000`

---

## `call run` — Swoole server

Requires the `swoole` PECL extension. If it is not loaded, `run` prints
a warning and exits.

### Options

| Option                     | Default     | Description                              |
|----------------------------|-------------|------------------------------------------|
| `--host=<host>`            | `0.0.0.0`   | Bind host                                |
| `--port=<port>`            | `8000`      | Bind port                                |
| `--workers=<n>`            | auto        | `worker_num` for Swoole                  |
| `--tasks=<n>`              | off         | `task_worker_num`                        |
| `--max_request=<n>`        | off         | `max_request` per worker                 |
| `--max_request_grace=<n>`  | off         | `max_request_grace`                      |
| `-w` / `--watcher`         | off         | Enable `MemoryWatcher` to recycle workers on RSS pressure |

CLI options are read by `ServerSettings::fromEnv()` together with the `SERVER_*`
environment variables; a discovered `WebConfigurer::configureServer()` then tunes the
result. Anything left alone falls back to Swoole's own defaults.

### Runtime behavior

On start, `run`:

1. Probes `host:port` — exits early if it's already in use.
2. Scans routes from `Kernel::$pathRoot` (`Router::fromScan()`).
3. Enables coroutine hooks (`SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_PROC`).
4. Boots the runtime in `RuntimeMode::Swoole`.
5. Swaps the log context to `CoroutineContext` so per-request fields
   (request_id, user_id, …) are isolated per coroutine.
6. Sets the `http` log channel as default.
7. Applies the Swoole options built from `.env`, CLI flags and the `WebConfigurer`
   (static files among them, when `staticPath()` was declared).
8. Sets a descriptive `cli_set_process_title()` for `ps` visibility.
9. With `-w`: wraps the request handler in `MemoryWatcher` to track
   per-request memory growth.

### Examples

```bash
call run
call run --port=8000 --workers=4 -w
call run --workers=8 --tasks=2
call run --max_request=5000 --max_request_grace=500
call run --host=127.0.0.1 --port=9501
```

---

## `call run dev` — PHP built-in dev server

A `passthru` wrapper around `php -S`. No Swoole, no workers, no
coroutines — just one process serving the declared static directory.

### Options

| Option         | Default     | Description                                    |
|----------------|-------------|------------------------------------------------|
| `--host=<host>`| `0.0.0.0`   | Bind host                                      |
| `--port=<port>`| `8000`      | Bind port; **auto-scans next 10 ports** if no `--host` and no `--port` were supplied and the default is busy |

If you specify `--host` or `--port` explicitly and that port is busy,
`run dev` prints a warning and exits instead of scanning.

### Examples

```bash
call run dev                  # 0.0.0.0:8000 (auto-scan if busy)
call run dev --port=9000
call run dev --host=127.0.0.1 --port=8080
```

Use this only for local development — there is no concurrency.

---

## Sample output

```
 | [============ Run ============]
 | [✓] Swoole server starting at http://0.0.0.0:8000
 |   Root            /srv/acme
 |   Workers         4
 |   Task-workers    off
 |   Max-request     5000
 |   Watcher         on
```

---

## Source

- `console/Command/Run.php`
- Routing: `src/Route/Router.php`, `src/Route/MemoryWatcher.php`
- Adapters: `src/Http/Adapter/Swoole{Request,Response}.php`

## See also

- [`../architecture/01-routing.md`](../architecture/01-routing.md) — how routes are scanned
- [07-mapping.md](07-mapping.md) — `Router` cache management
- [`../configuration/09-web-server.md`](../configuration/09-web-server.md) — every server setting
- [`../configuration/08-runtime.md`](../configuration/08-runtime.md) — the runtime and its caveats
- [`../configuration/02-logging.md`](../configuration/02-logging.md) — per-coroutine log context
