# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [4.4.0] — 2026-10-29

The application becomes a supervisor: `call run` is PID 1, and it starts, watches and restarts
every component it declares. `storage/` is gone. The HTTP server learns its address from the
code, and gets IPv6, TLS, HTTP/2, a `Server` header and compression settings. A WebSocket can
serve `wss://`. The actuator shows only `health` by default.

Several changes break existing projects. **Upgrading from 4.3** at the end lists what to do.

### Added

**The application supervises its components.** `call run` (and `call application start`) is
now PID 1 in both modes, with HTTP or without it. The HTTP server is one component among the
others, and each component runs in its own process. A component counts as started once it is
ready:
- a Process has entered `run()`;
- a Scheduler has started its loop;
- a WebSocket holds its port;
- a Daemon has its workers running.

A component that fails while running is restarted after 1, 2 and 4 seconds, up to `retries`
times in a 30-minute window. A component that fails on start, or is not ready within
`startTimeout`, is not retried. Once the retries run out, a `critical` component stops the whole
application with exit code 1 and one line giving the reason. A non-critical one is marked
`FAILED`, and `health` reports `degraded`. A Daemon is never restarted, because it supervises
its own workers.

`#[EnableHttp]`, `#[EnableProcess]`, `#[EnableScheduler]` and `#[EnableWebSocket]` take
`critical`, `retries` and `startTimeout`. `#[EnableDaemon]` takes `critical` and `startTimeout`.
The defaults are critical, 3 retries and 60 seconds, and a `startTimeout` of 0 turns the timeout
off. Signals sent to PID 1 (`SIGHUP`, `SIGUSR1`, `SIGUSR2`) reach the HTTP component.

**Components declared in code.** The application class can implement
`protected static function components(Components $components)`. It runs after the scan, with
the container available, and takes the same parameters as the attributes. It accepts
`process()`, `scheduler()`, `websocket()` and `daemon()`. It also accepts `script()` and
`command()`, which run an external program:
- the program is ready as soon as it starts;
- exit 0 means the work is done, and any other code is a failure;
- it is stopped with `SIGTERM`, then `SIGKILL` after `grace` seconds;
- its output goes to the application log.

Attributes and the hook add up, and the same class or program name declared twice stops the
boot.

**`call application` (alias `app`).** The subcommands:
- `info` shows the manifest, where each component came from, the listen addresses and the
  server profile;
- `status` shows the running application and its components, both declared and adopted;
- `stop` stops the application, waiting up to `--timeout` seconds, or kills it with `--force`;
- `start [dev]` is the same as `call run`.

**Managing a component of the running application.** While the application runs:
- `X::stop()` parks a component;
- `X::dispatch()` resumes it, a `FAILED` one included;
- the new `X::relaunch()` starts it again in a fresh process;
- `call process|daemon|schedule … start|stop|restart` go through the application, and the
  console shows `STOPPED`, `FAILED` and `(application)` / `[adopted]`.

A process the application does not declare is *adopted* when it is started with `dispatch()`.
It runs as a non-critical component without retries. Starting a component of an application
that runs on another host (another container) throws `ProcessManagedException`, which suggests
`docker exec`. `ProcessStatus` gained `host` and `managedBy`.

**Listen addresses in the code.** `#[EnableHttp(port: 8000, host: '0.0.0.0')]` declares the
HTTP address. The launch flags are named after the component: `--http.port` and `--http.host`,
and `--<name>.port` and `--<name>.host` for a WebSocket (the name `app info` shows). The
precedence is declaration, then flag, then `ServerConfigurer::port()` / `host()`, which has the
last word and logs a warning when it overrides a flag. Ports are checked to be free before the
application claims its record.

**IPv6.** A host containing `:` binds an IPv6 socket, so `::` no longer fails with "Invalid
IPv4 address". An address like `::ffff:a.b.c.d` is reported as `a.b.c.d` by `getClientIp()`
and the WebSocket `Connection`. The banner, the log and `app info` put brackets around v6
addresses.

**HTTPS and HTTP/2 on Swoole.** You can enable TLS in two ways:
- in code: `ServerSettings::tls($cert, $key)`;
- in the environment: `SERVER_TLS_CERT` / `SERVER_TLS_KEY`.

Relative paths resolve from the project root. HTTP/2 is a separate switch, `http2()` /
`SERVER_HTTP2`, which is off by default and also works without TLS (h2c). The boot stops in
these cases:
- only half of the certificate pair is given;
- a file is unreadable or is not PEM;
- the key belongs to another certificate;
- Swoole was built without OpenSSL.

Under TLS, `getScheme()` returns `https`, and `X-Forwarded-Proto` still wins.

**`wss://` for WebSocket.** `protected bool $tls = true` on a `WebSocket` class makes it
serve TLS on its port, and only TLS. The certificate is the HTTP server's
(`SERVER_TLS_CERT` / `SERVER_TLS_KEY`) unless `protected static function tlsFiles()` names
another. The files get the same checks as the HTTP server's: a declared socket is checked
at boot, and the error names the class. A socket started on its own is checked when it
starts. `getScheme()` in `onHandshake()` returns `https`, and the log, the banner and
`app info` show `wss://`. HTTPS on the HTTP server never switches it on.

**The `Server` header and compression.** Responses carry `Server: Winter` instead of
`swoole-http-server`. Change it with `SERVER_HEADER` or `serverHeader()`; an empty value or
`false` removes it. A response that sets its own `Server` keeps it. Compression is set with
`SERVER_COMPRESSION`, `SERVER_COMPRESSION_LEVEL` and `SERVER_COMPRESSION_MIN`, or with
`compression()`, `compressionLevel()` and `compressionMinLength()`. The defaults are on, level 1
and 1 KB (Swoole's own minimum is about 20 bytes).

**Actuator exposure and guard.** `#[EnableActuator]` gains these parameters:
- `expose` takes a list of endpoints, or `['*']`; an unexposed endpoint answers 404, and a name
  that is not an endpoint stops the boot;
- `middleware` guards the actuator;
- `guardHealth` puts `/actuator` and `/actuator/health` under the guard too (they are open by
  default).

**Health thresholds.** The disk check measures the project's disk, not `/`. Disk is `degraded`
at 90% used and `down` with less than 10 MB free. Memory is at worst `degraded`, at 90%. The
thresholds are `protected const` on `HealthIndicator` and can be overridden. The db and redis
checks are cached for 5 seconds per worker (`checked_at`), and a db or redis with nothing
configured is not reported.

**`LOG_DIR`.** With `LOG_OUTPUT=file`, logs go to `LOG_DIR`, one file per channel. The path is
relative to the project root unless it is absolute, and the default is `<root>/logs`. A
directory the user cannot write stops the boot, naming the directory and the user.

**`HttpRequest`.** `getServerParam()` accepts a key in either case under both Swoole and FPM.

### Changed

**`.winter/` replaces `storage/`.** The kernel keeps its files in `<root>/.winter/`, a fixed
path that cannot be overridden:
- `cache/` holds the `#[Cacheable]` file store, one folder per cache, created on the first
  write;
- `build/` holds `di.php`, `proxy.php` and `proxy/`, which used to live in
  `/tmp/flytachi.winter.volatile.<project>`;
- `runnable/` holds the records and locks of the application and its processes.

The class scanner and the dev watcher skip `.winter/`.

**The process environment wins over `.env` in the CLI.** Under a `variables_order` without `E`
(a typical local `php.ini`), a variable from the process environment was invisible to `env()`.
If `.env` also had it, it came back as `null`. The kernel now imports the process environment
before `.env`, so `env()` returns the process value whatever the `variables_order`. FPM is not
affected.

**Actuator endpoints.** Only public, non-static methods are endpoints, under their declared
name. A protected or private method used to answer 500; it now answers 404. A
`HealthContributor` that throws, or cannot be built, marks only its own component `down` with
`check failed` and logs the exception. It no longer turns the whole `health` into a 500. A
broken db or redis configuration does the same. Error texts in `health` details show only with
`DEBUG=true`; otherwise they read "check failed" or "connection failed".

**`cfg docker`.** The template now:
- builds on the [`flytachi/winter`](https://hub.docker.com/r/flytachi/winter) runtime image
  (Swoole, pcntl, bcmath, pgsql, mysql, redis and opcache, user `winter`);
- puts the project in `/app`;
- has the image's entrypoint start the application as PID 1;
- no longer has the `docker/` folder.

Existing files are not overwritten without `-f`, and every file is reported as `EXISTS`,
`CREATED` or `OVERWRITTEN`.

### Deprecated

**`#[EnableWeb]`** is replaced by `#[EnableHttp]`, which takes the address and supervision
parameters. `#[EnableWeb]` still works as `#[EnableHttp]` with defaults; using both stops the
boot.

### Removed

- **`storage/`** and the `call storage` command.
- **The bare `--port` / `--host`** launch flags. Each now stops the boot with a hint to use
  `--http.port` / `--http.host`.
- **`LOG_FILE`, `LOG_SYS_FILE` and `LOG_HTTP_FILE`.** Each now stops the boot with a hint to
  use `LOG_DIR`.
- **From `Kernel::init()`:** the parameters `pathStorage`, `pathStorageLog`,
  `pathStorageCache`, `pathStorageRunnable` and `isTmpVolatile`.
- **From `Kernel`:** the properties `$pathStorage*`. Use `$pathWinter`, `$pathCache`,
  `$pathBuild`, `$pathRunnable` and `$pathLogs` instead.
- **From `KernelStore`:** `store()`, `volatile()`, `showStorages()` and `showVolatiles()`.

### Fixed

- Two projects in folders with the same name shared one class and proxy cache in `/tmp`. Each
  project now has its own cache in `.winter/build/`.
- A fresh project could run without a working process lock, and nothing reported it. The lock
  now creates its own folder.
- `ServerSettings` suggested `ssl_cert_file` for HTTPS. The server was built without TLS, so
  the handshake failed and it kept serving plain HTTP.

### Upgrading from 4.3

1. Stop the running application before updating. The new kernel no longer reads the records
   the old one kept in `storage/runnable`.
2. In scripts, Docker and process managers, replace `call run --port=N` with
   `call run --http.port=N`, or declare the port in code with `#[EnableHttp(port: N)]`.
3. Replace `LOG_FILE` / `LOG_*_FILE` with `LOG_DIR`, if they were set.
4. Add `/.winter` and `/logs` to `.gitignore`. Remove `storage/` once nothing points at it.
5. Remove the `storage` path arguments from any custom `Kernel::init()` call.
6. If the actuator served more than `health`, list those endpoints in
   `#[EnableActuator(expose: [...])]`.
7. Optionally, regenerate the Docker files with `call cfg docker -f` to move to the
   `flytachi/winter` image.

[Unreleased]: https://github.com/flytachi/winter-kernel/compare/v4.4.0...HEAD
[4.4.0]: https://github.com/flytachi/winter-kernel/compare/v4.3.1...v4.4.0
