# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Schema versions** (needs winter-ppa with `Schema`): a `#[Migratable]` database's schema follows
  its entities version by version, the history in `ppa/<Config>/` — the config's class name as it
  is (`App\Config\PgDb` → `ppa/PgDb/`), the code of its versions in `ppa/<Config>/custom/`;
  `db status` points at a folder of versions no config is named after (a config renamed).
  - **`call db snapshot`** records what the entities changed since the last version
    (`--name=`, `--drop=t.c,…`, `--keep=t.c,…`, `--db=`, `--dry`); it refuses what would lose data
    or guess: a rename without `#[RenamedFrom]`, a column gone without `--drop` / `--keep`, a NOT
    NULL column without a default, a change the driver cannot make.
  - **`call db snapshot custom <name>`** — the same with a class for the version's code
    (`before()` / `after()`); a version may be code only.
  - **`call db snapshot clean`** removes the `#[RenamedFrom]` every database's history has
    recorded — an entity of several databases keeps it until the last one has.
  - **`call db status`** — versions applied, pending or failed, and whether the entities moved on.
  - **`call db migrate`** applies the pending versions of a database with a history (`--dry`
    prints the statements); a database without one keeps the create-if-missing pass of 4.x and is
    told how to start a history. A failed database ends the command with 1.
- **`#[EnablePpaMigration]`** on the application — the pending versions of every database are
  applied at start, in the onStarting slot: after the High components, before anything else; a
  version that fails stops the start. Refused at once without winter-ppa. What it did is said in
  the console's lines when the output is a terminal, in log entries when it is not (a container);
  nothing when there is nothing to apply.
- **The start checks the schema versions** (files and entities, no database): a database whose
  entities moved on from its last version — a snapshot forgotten — is said on every `call run`;
  under `call run dev` so is a history whose versions are not applied because
  `#[EnablePpaMigration]` is not set.

- **The start waits for another instance's migration as long as it runs** — two replicas starting
  together: the second used to give up after 300 s, fail its start and be restarted to wait again,
  later each time (CrashLoopBackOff). It says it waits every 30 s; `call application alive` stays 0
  meanwhile. `call db migrate` keeps its 300 s.
- **`call application alive`** — for a liveness probe: exit 0 while the supervisor runs and
  reports, a start that migrates for minutes included (`alive: starting, onStarting running`);
  `dead: …` and 1 when it does not run or has been silent past 30 s. `health` stays what it was —
  ready, every critical component up — and says `unhealthy: starting, onStarting running` during
  onStarting instead of every component held back. A k8s liveness probe on `health` or on
  `/actuator/health` (no port before onStarting is done) killed a long migration midway, and the
  next start began it again.

- **`call db sql` and `call db migrate` say what a server would not build as the entities declare
  it** (winter-ppa's `SchemaCheck`: an index method the server has not, a partial unique index on
  MySQL, an operator class without its extension…): `sql` says it, `migrate` of 4.x creates nothing
  for that database. `db snapshot` / `status` pass the config's `#[Extension]`s to the versions.

### Changed

- **`Application up` is said when it is true** — every component started, after onStarting and
  the migrations — not before the supervisor starts anything: with a migration of minutes the
  line no longer stands minutes ahead of an open port. The banner's `✔ Application up in …` moved
  with it; `Banner::print()` takes the rows only, `Banner::printUp()` says it is up.
- ⚠️ **Without `LOG_LEVEL` the logs are no longer off** — `sys` and `http` write `critical` to
  stdout (`LOG_OUTPUT`, `LOG_FORMAT` apply as with a level). Before, an unset `LOG_LEVEL` sent
  every channel to nothing; to keep that, set `LOG_OUTPUT=null`. A channel's level is still
  `LOG_<CHANNEL>_LEVEL`, else `LOG_LEVEL`, else the default — now `critical`, before `info`
  (`Kernel::channel('job')` included). `call application info` shows `critical (default)`, `/actuator` the
  level instead of `disabled`.
- **Channels `ppa` and `redis`** — added only when winter-ppa / winter-redis is installed; `info`
  without a level set, so what a start migrated and a pool losing connections are seen with no
  configuration (`LOG_PPA_LEVEL=debug` shows every borrow and release, the rest stays quiet).
  The connection pools, the schema versions at start and their checks write there under their
  class names (`PpaConnectionPool`, `RedisPool`, `PpaSchema`) — before, in `sys` as `PPA`,
  `Redis` and `Migrations`. `/actuator` lists them.
- **A failed start is said once** — the line on stderr; the logger has it too only when a level
  is set (`LOG_LEVEL` / `LOG_SYS_LEVEL`), not twice in `docker logs` by the default `critical`.

## [4.7.2] — 2026-11-21

A new class is found by the next start and by `call run dev` with `DEBUG=false`, and a
controller parameter no request can fill is said at start.

### Added

- **A controller parameter no request can fill is a warning at start.** Without a source
  attribute a parameter is looked up among the path variables only — a query parameter
  needs `#[RequestParam]`. Forgetting it was silent: `?string $q = null` under `/search`
  was null whatever `?q=` said, `int $page = 1` was always 1, and a required `string $q`
  failed every request with 500, while the route was registered and listed as if nothing
  were wrong. Now a scalar parameter (`int`, `float`, `string`, `bool`, a backed enum,
  `BcMath\Number`, `Decimal`) with no source attribute and no path variable of its name is
  said once at start, in the `sys` log, with what it always is:
  `Route GET /search (…::search): $q is never taken from the request — it is not a path
  variable, and a query parameter needs #[RequestParam]. It is always null.`
  `call mapping show` lists the same under the routes. Binding is unchanged, and nothing
  is checked per request: the route table already knows the parameters and the path.

### Fixed

- **`call run dev` sees a new class.** With `DEBUG=false` it scanned from the class-list
  cache (`.winter/build/di.php`): an edit of a controller came through, but a controller
  added under it restarted the application and still answered 404 — the list was the one
  of the last start. Under the DevWatcher (`run dev`, `-w`, `--watcher`) the project is now
  walked on every start, and the list a start left behind is deleted, so the commands run
  beside it do not read it either.
- **A console command no longer writes the class list.** The first command with
  `DEBUG=false` — a bare `php call` included — wrote it, and nothing rewrote it: a class
  added afterwards stayed invisible to the next `call run` until `call di clean`. A command
  now reads the list when there is one and walks the project when there is not.

### Changed

- **`call run` / `call application start` writes the class list anew** — one walk at start,
  and the router, the health report and every later command read that list: it is the
  code that is running, outside Docker too (a `git pull` and a restart without
  `call di build`). `call di build` is unchanged and still checks the proxies and the cache
  names before a start. A container whose entrypoint runs `di build` then walks the project
  twice — about 10 ms per 200 classes each time. `DEBUG=true` is unchanged: walked, the
  file left alone.

## [4.7.1] — 2026-11-21

### Fixed

- **A Server-Sent Events stream no longer holds a database or Redis connection.** A coroutine
  keeps the pooled connection of its first query until it ends, and a stream's coroutine ends
  when the tab closes: with `#[WebAuth]` or any middleware that read the session, each open
  tab held a connection for hours, and a few tabs emptied the pool — every other request of
  the worker waited for `poolWaitTimeout` and failed. When a stream starts, its request's
  connections now go back to their pools (`PpaConnectionPool::releaseCurrent()`,
  `RedisPool::releaseCurrent()`); whatever it needs later — an `onClose` that writes — borrows
  again, and a connection inside a transaction is kept, with a warning. Needs winter-ppa 1.3 /
  winter-redis 1.2; with older ones, nothing changes.

### Changed

- Requires **winter-cdo `^4.1`**: PostgreSQL connections carry `tcp_user_timeout=10000`, so a
  query on a socket the network dropped in silence fails in 10 s instead of hanging for
  minutes — and a worker's stop no longer waits on it. With winter-cpool 1.3 (through
  winter-ppa 1.3 / winter-redis 1.2) a liveness check that does not answer is no longer waited
  for beyond 3 s.

## [4.7.0] — 2026-11-20

Server-Sent Events: a controller returns a stream, and the browser's `EventSource` gets its
events as they come. And the proxy headers are believed only from the application's own
proxies.

### Upgrading — behind a proxy

**Forwarded headers are no longer believed from anyone.** Behind nginx, HAProxy, Nginx Proxy
Manager or an ingress, add the proxy, or `getClientIp()` is the proxy's address,
`getScheme()` is `http` behind TLS termination (cookies lose `Secure`), and `getBaseUrl()`
carries the server's own port — links in mail and redirects break:

```php
public function configureServer(ServerSettings $server, ApplicationArguments $args): void
{
    $server->trustedProxies('172.16.0.0/12');   // docker network; k8s: the ingress pod network
}
```

`getHost()` is unchanged where the proxy passes `Host` as it is (nginx with
`proxy_set_header Host $host`, Nginx Proxy Manager, HAProxy by default). Only `X-Forwarded-*`
is read by default; a proxy that passes the client in `X-Real-IP` alone needs
`->trustedHeaders(ProxyHeader::XForwarded, ProxyHeader::XRealIp)`. Until the proxy is added,
the log says which address sent the headers that were ignored. WebSocket handshakes read the
same list — behind a proxy, add it for them too.

### Added

- **`SseEmitter`** (`Flytachi\Winter\Kernel\Http\Sse`) — a stream a controller returns.
  Events come from a function run beside it (the stream ends when it returns) or are
  `push()`ed from other requests of the worker; `complete()`, `isOpen()`, `onClose()`,
  `lastEventId()` (the browser's `Last-Event-ID` after a reconnect — known in the
  controller, to send what was missed; what a controller pushes before the stream starts is
  not capped). `200` with `text/event-stream`, `Cache-Control: no-cache`,
  `X-Accel-Buffering: no`, never compressed; `retry` first, a `: ping` heartbeat after
  `heartbeat` seconds of silence. A client gone is noticed at the next write; a client too
  slow to take `capacity` events is cut off rather than left behind. An exception after the
  headers is logged and closes the stream; `HEAD` gets the headers only. A stream that never
  reaches its response (the controller threw, the deadline answered) still runs its
  `onClose`; the deadline that ends a capped stream does not cancel its `onClose`.
- **`SseEvent`** — `data` (a string as it is, anything else JSON; lines become `data:`
  lines), `event`, `id`, `retry`; a line break or NUL in `event`/`id` is refused.
- **A stream is not under the request's deadline** (`SERVER_REQUEST_TIMEOUT`) — no
  `#[Timeout(0)]` needed; a `#[Timeout(n)]` of its route caps it. Marker: `Streaming`.
- **Streams close when the worker stops** — `docker stop`, `call application stop`, a worker
  recycled, a `call run dev` reload: their connections are cut, so nothing waits for them —
  a client that stopped reading (a dead network) and a producer waiting between its events
  included.
- **`StreamingResponse`** — `write()`, a response written in parts, and `close()`, its
  connection cut at once (reset by the server: what waits in its buffer is dropped). The
  Swoole adapter's. `HttpResponse` is unchanged.

### Security

- **Forwarded headers count only from a trusted proxy.** `getClientIp()`, `getScheme()`,
  `getHost()`, `getPort()` and `getBaseUrl()` took `Forwarded`, `X-Real-IP` and
  `X-Forwarded-*` from any client: a service reachable past its proxy (a NodePort, a
  published container port) took `curl -H "X-Real-IP: 1.2.3.4"` for the client 1.2.3.4 — in
  audit logs, IP rate limits, IP allow-lists — and a forged `X-Forwarded-Host` for the host
  of the links it built. Now they are read only on a request from
  `ServerSettings::trustedProxies()` — addresses, networks (IPv4, IPv6) or `*` (the peer,
  whoever it is: only it is a proxy, and the rightmost hop is the client); nobody by
  default, and no environment variable: the application decides where the list comes from.
  A wrong value stops the boot by name; the banner and the boot log say whom it trusts.
- **Only the headers the proxy sets.** A proxy passes on a client's headers it does not set
  itself — nginx appends `X-Forwarded-For` and forwards `Forwarded` and `X-Real-IP` as the
  client sent them. So only `X-Forwarded-*` is read by default; `X-Real-IP` and `Forwarded`
  when named: `ServerSettings::trustedHeaders(ProxyHeader ...)`. With several, the address
  comes from `X-Forwarded-For`, then `Forwarded`, then `X-Real-IP`; an `X-Real-IP` naming a
  trusted proxy is not the client. `Forwarded`'s `proto` and `host` come from the client's
  element, not the leftmost one; a quoted value is not split.
- **`X-Forwarded-For` and `Forwarded` are read from the right.** The leftmost public address
  was taken — the part the client writes itself, forgeable behind a trusted proxy too; now
  the first address that is not a trusted proxy, counted from the right. A client in a
  private network is no longer passed over. A port (`ip:port`, `[ipv6]:port`) and a zone are
  cut off a hop; a hop that is no address (`unknown`, `_hidden`) stops the walk — the proxy
  is then the client. Every spelling of an IPv4-mapped address is its IPv4.
- **WebSocket handshakes read the same trusted proxies** as the HTTP server — the
  application's, installed at boot (with no HTTP server, from its `configureServer()` all
  the same). `X-Forwarded-Port` outside 1–65535 is ignored.
- **Forwarded headers from an untrusted sender are a warning** — once per sender per worker,
  for 32 senders at most: a proxy missing from the list, or a client passing for someone
  else. The header values go to the record's context, line breaks removed. Both adapters
  read them the same way (`FpmRequest` included).

### Fixed

- **A console command that fails exits with 1.** An exception escaping a command was printed
  and the process ended with 0 (`die()`): a script, a CI job or a container's entrypoint
  took the failure for a success. `Printer::printError()` now exits with 1, or with the code
  it is given (`printError($e, 3)`).

## [4.6.0] — 2026-11-17

A frontend — a single-page app, a pre-rendered site — served by the application itself,
beside its API; under `call run dev` its development server behind the same address.

### Added

- **`Frontend`** — the contract of a frontend a package prepares (winter-front-nuxt): its
  **`FrontSite`** (the built files, the shell a single-page app answers every route with,
  where it answers: a path on the HTTP server's port or a port of its own, with `routes` and
  `exclusive`), what the image needs and its **`FrontBuild`**. The application declares it
  with **`Components::frontend()`**; `WinterApplication::frontends()` lists them. Its
  **`hints()`** — advice about its own setup (a config that does not match its mode, a build
  not made yet) — are logged as warnings once, when `call run` starts serving; a check that
  fails is a warning too, never a reason to stop.
- **The HTTP server serves frontends after the routes.** A controller always wins; a GET or
  HEAD no route took goes to the frontend there: a file of its build sent as it is
  (`sendfile`), with its type, `ETag`/`Last-Modified` (a match answers 304), a precompressed
  `.br`/`.gz` the client accepts, content-hashed files (`assets`, `/_nuxt`) cached for good
  and the rest revalidated; an unknown page gets the shell when the request takes HTML; a
  pre-rendered site's page that was not built gets the site's own `404.html` with 404 when a
  page is asked for; a missing content-hashed file is a 404 never stored; nothing outside
  the build is ever sent. A POST, PUT or DELETE no route took is the backend's 404/405,
  never the shell. A GET on a path with another method's route goes to the frontend only for
  an HTML request. A browser's `fetch()` (`Sec-Fetch-Dest` other than `document` or
  `iframe`) never gets the shell: a typo in an API path is a 404 there, not a page with 200.
  A rebuild is seen at once — no restart, no stale `ETag`.
- **A frontend on a port of its own** — the HTTP server listens there as well
  (`addListener`): the same workers, the same router. Only its `routes` reach the
  controllers there, and those prefixes are closed on the HTTP server's port unless one of
  the frontends naming them has `exclusive: false` (said at start). A prefix named by
  several frontends is open on each of their ports; `/` names every route. The port a
  request came in on is the request's `server_port`.
- **A frontend under the supervisor** — no process of its own: up while its build is there,
  failed (with why) while it is not, looked at again every 3 s and up once the build
  appears; not critical and `Priority::Low` by default; a build that is missing answers 503.
- At start: a frontend needs the HTTP server; two frontends in one place, a frontend on the
  HTTP server's port, a port used twice are refused; a controller route taking a page of a
  frontend on the HTTP server's port is warned of (`overlap: false` silences it). The banner
  names each frontend and where it answers.
- **`call service docker`** writes a frontend's build: a stage of its own on Node before the
  image's last stage (the manifest, the install, the sources, the build), and its output
  copied into the last stage after the code — the frontend's folder emptied first, so the
  image keeps the build and not the sources (a frontend inside another's folder goes with
  it) — between marks, replaced in place, removed with no frontend left; a parser directive
  (`# syntax=`) stays on top. A `.dockerignore` that does not leave out `node_modules`,
  `.nuxt`, `.output` is pointed out (the host's `node_modules` would go into the image).
- **`nuxt dev` behind the application's address** — **`Frontend::devServer()`** gives a
  **`FrontProgram`**: the frontend's development server, asked only under `call run dev` (in
  production a frontend is its built files, and nothing runs Node). The kernel runs it as an
  outside program under the supervisor on a free loopback port (`PORT`, `HOST=127.0.0.1` in
  its environment), ready once it listens, restarted when it crashes, its output in the log.
  The HTTP server forwards to it the GET and HEAD no route took — pages and files, a
  development server's own — with the coroutine HTTP client: the client's address in
  `X-Forwarded-For`/`-Proto`/`-Host`/`-Port`, asked as `localhost` (a development server
  refuses names it does not know), every `Set-Cookie`, the body whole. A program not
  listening — starting, down — answers 503 with `Retry-After`; one that does not answer
  within the request timeout, 504 (no request timeout: no limit).
- **No loop through a development server.** Every request forwarded to it carries
  `X-Winter-Forwarded`; one that comes back to the application with it — a Nitro route or
  `devProxy` handing a path back, the request's headers carried along — finds a controller
  or the application's 404, and is never forwarded again (the live-reload socket too).
- **The live reload of a development server through the application's address.** Under `call
  run dev`, with a development server, the HTTP server is a `Swoole\WebSocket\Server` and
  relays the browser's WebSocket (HMR, `vite-hmr`) to it — on a frontend's own port too;
  asked as `localhost` (a development server refuses names it does not know). In production
  the server stays the plain `Http\Server`.
- **`ServiceImage`** — `devPackages` and `devRun`: installed only in an image built with
  `DEV=true` (`ARG DEV=false` in the services' block). `call service docker` takes a
  frontend's image into the block (Node for `nuxt dev`, in a development build only), makes
  each frontend's `node_modules` a folder of winter's in a development build, and says what
  compose needs for `call run dev` in Docker: the `DEV` build argument and a `node_modules`
  volume per frontend.

### Changed

- **`Flytachi\Winter\Kernel\Dev`** — what only `call run dev` runs lives together:
  `DevWatcher` moved there from `Route` (a `use` of `Route\DevWatcher` changes), beside the
  frontend's dev-server forwarding and live-reload relay.
- `call cfg docker`'s `.dockerignore` leaves out `**/node_modules`, `**/.nuxt`,
  `**/.output`.
- `call run dev`'s watcher no longer walks `.nuxt` and `.output`.

### Fixed

- `call run dev` restarted the application over and over while pnpm installed in a
  container: a link to a folder (pnpm's store links each project) was taken for a source
  file. Only `.php` files count.

## [4.5.2] — 2026-11-15

### Fixed

- Shell completion offers `call application health`, and `call service docker`'s
  description says what it does now (the Dockerfile block; compose is only shown).

## [4.5.1] — 2026-11-15

A health check for containers.

### Added

- **`call application health`** — the application's health for a container's check: one
  line and an exit code. 0 while every critical component runs (a process whose work is
  done, and one held by an outside process, count): `healthy: 4 component(s) up`, or
  `healthy, degraded: …` with a non-critical one failed or a component stopped on
  request. 1 when a critical component is starting or waits for its restart
  (`unhealthy: postgres restarting (1 of 3)`), when the supervisor has been silent past
  30 s — it hangs itself —, or when the application does not run. Components started on
  request are left out. It asks nothing over the network: it reads what the supervisor
  publishes, in any application, with HTTP or without. The healthy line goes to stdout,
  the unhealthy one to stderr.
- **`call cfg docker`** writes a `healthcheck` with it into a new project's
  `docker-compose.yml`: `php call application health` every 30 s, 5 s for an answer, 60 s
  of start period (a first start initialises the databases; a service not ready within
  its 60 s start timeout stops the application anyway) checked every 5 s
  (`start_interval`: Compose 2.20+, Engine 25+), 3 failures in a row.
- Shell completion knows `call application health` (and `call app health`).

### Fixed

- The completion's hint for `call service docker` still said it writes a compose
  override; it writes the Dockerfile block and shows what compose needs.

## [4.5.0] — 2026-11-15

Services: a database or a cache the application runs in its container, packaged with
everything it needs — and a command that writes what the image needs for them. An outside
program's output can be read into the application's log.

### Added

- **`Service`** — the contract of a service: `component()` (what the supervisor runs: an
  outside program with its checks, stop signal and priority) and `image()` (what the
  container image needs for it). The kernel knows no service by name; a package — or the
  project — provides one, and the application declares it with
  **`Components::service()`**. `call app info` names its source `service(Name)`.
- **`ServiceImage`** — what a service needs in the image, for apk (flytachi/winter is
  Alpine): `packages`, `directories` (owned by winter, with a mode), `volumes` (name →
  path), `env`, and `run` for what the rest does not cover. A value the Dockerfile cannot
  take is refused when the object is made. `ServiceImage::merge()` makes one block of
  several: one `apk add`, one `mkdir`/`chown`, each thing once; two services wanting one
  thing two ways — a directory with two modes, a variable with two values — is an error.
- **`call service list`** — the declared services: class, the Composer package it comes
  from, priority, packages, volumes, environment.
- **`call service docker`** — writes the block into the `Dockerfile`, between
  `# winter:services` marks (before the first `COPY` of the last stage the first time,
  replaced in place after, removed with no services left). Compose is the developer's and
  is never written: the command prints what to add to `docker-compose.yml` —
  `stop_grace_period` (the application's stop time + 5 s) and the volumes for the services'
  data, to take or leave out. `--dry` shows without writing; `--service=` names the compose
  service in the hint.
- **`output:`** of `command()` and `script()` — a **`ProgramOutput`** that reads the
  program's output into the application's log. Its stdout and stderr come to the
  supervisor over a pipe instead of the application's own; each line is read into an
  **`OutputLine`** — an entry at a PSR-3 level (with the PID of whoever wrote it), more of
  the entry before (into its message or under a key of its context), or nothing — and
  logged with the application's handlers under the program's name: `LOG_LEVEL`, the line
  or JSON format and the log file apply. An entry is logged once the next one starts or
  the program is quiet for 50 ms; a line the parser throws on is logged as it is; each
  look reads a bounded portion, so a program that writes without pause does not hold the
  loop. A line is cut at 128 KB (the rest of it dropped), an entry at 3 MB, ending with
  how much was cut (`… (cut: 9.2 MB more)`); under `call run dev`, 1 MB and 16 MB. Without `output`, the program writes straight to the application's output, as
  before. The PID goes to `extra.program`, which winter-logger's line format shows in
  place of the application's, its channel in blue.

## [4.4.4] — 2026-11-13

An application with an outside program no longer keeps a CPU core busy.

### Fixed

- The application spun at full CPU for as long as an outside program (`command()`,
  `script()`) ran. The supervisor opened a channel to every component, a program among
  them; a program closes its end on exec, and the supervisor's end, at EOF for good, was
  readable at once on every look — the loop's 0.1 s wait never waited. A program gets no
  channel now, and a channel closed on the other side is no longer listened to.

## [4.4.3] — 2026-11-13

The application starts and stops its components in order, and gets two hooks of its own:
one before it serves, one after it has stopped. A database or Redis can run inside the
application, as a component the rest waits for.

### Added

- **`Priority`: start and stop order.** Every component takes `priority:` — High, Normal
  (the default) or Low, in the `#[Enable*]` attributes and in `components()`. The higher,
  the longer it lives: components start level by level, a level once every component of
  the one before it is ready (or settled — failed and not critical, held by an outside
  process, finished); a stop goes the other way, a level once the one before it is down.
  High is for what the rest stands on (a database, Redis), Low for what takes outside
  traffic (HTTP, WebSocket). The log says each level; `app status` shows a `PRIORITY`
  column and what a waiting component waits for. With no priority set, everything starts
  and stops at once, as before, and nothing more is logged.
- **Outside programs that others can stand on.** `command()` and `script()` take:
  - `ready:` — how the program is known to be ready: a command that succeeds once it is
    (`['pg_isready', '-q']`, its output discarded) or `tcp://host:port`; asked every half
    second for up to `startTimeout` (60 s). Without it a program is ready once it runs, as
    before;
  - `alive:` — a check that the program still answers once it is ready: a command or
    `tcp://host:port` with the default timing, or a `Probe` with its own —
    `Probe::command([...], every: 10, timeout: 5, failures: 6)` by default. Failures count
    only in a row (one pass resets the count), each is logged and shown in `app status`;
    `failures` in a row mean the program hangs, and it is stopped step by step — its
    `stopSignal`, then SIGTERM halfway through its `grace`, then SIGKILL at the end of it —
    and restarted as crashed. Off unless set;
  - `stopSignal:` — what asks it to stop: SIGINT for PostgreSQL's fast shutdown;
  - `retries:` — restarts after a crash, as for processes. 0 by default, as before.
- **`onStarting()` and `onStopped()` on the application.** `onStarting()` runs once the High
  components are ready and before the rest starts — migrations, with the container and the
  database layer there. It runs in a process of its own (what it opens is not inherited
  by the components); a throw is logged, nothing more starts, and the application exits
  with 1. `onStopped()` runs once everything is down, in the application's process, for at
  most 5 seconds; an exception is logged and the stop goes on. Neither runs on a reload
  (SIGHUP) or for console commands; under `call run dev` both run on every restart.

### Changed

- The stop budget adds the levels up: the longest grace of Low, plus Normal's, plus
  High's, plus 5 seconds (never less than 35). With one level it is what it was. Raise
  `stop_grace_period` (Docker) or `terminationGracePeriodSeconds` (Kubernetes) to match
  when a High component has a long grace.

## [4.4.1] — 2026-11-11

The class scan reads the project's code, not everything under its root. A stop has a finite,
canonical wait at every level.

### Changed

- **Bounded, canonical stop times.** Every level of a stop has a finite wait now, each one
  inside the next:

  | Level | Waits | Before |
  | --- | --- | --- |
  | Process, Scheduler, WebSocket (`$grace`) | 30 s, then forced down | 0 = for good |
  | Daemon workers (`$grace`) | 30 s, then SIGKILL | 30 s |
  | HTTP requests in flight | as long as a request may run (`requestTimeout`, 30 s) | 3 s (Swoole's default) — longer requests were cut |
  | Outside programs (`script()`, `command()`) | 30 s, then SIGKILL | 10 s |
  | The application | 35 s, or 5 s past the longest grace; then SIGKILL to what is left, named in the log | for good |
  | A second stop signal (Ctrl+C twice) | kills what is left at once | ignored |
  | The sentinel | 30 s, then SIGKILL | 10 s |
  | `docker stop` (generated compose) | `stop_grace_period: 40s` | Docker's 10 s |

  30 seconds is the usual bound (Kubernetes' grace period, Spring Boot's shutdown phase). A
  `$grace` of 0 still waits for good, and then the application sets no budget of its own.
  For Kubernetes, set `terminationGracePeriodSeconds: 40`.

- **The scan covers what `composer.json` autoloads.** The project's classes are looked for in
  the folders of its `autoload` section — `psr-4`, `psr-0` and `classmap`, not `autoload-dev`
  — and in the file that declares the application class (`bootstrap.php`), so a class
  declared there is still found. Before, the scan walked the whole root: every folder
  (`.git`, `vendor/`, `tests/`, `scripts/`, data folders), every `.php` in them read, and
  every class file the autoloader did not know `require_once`d. That made three things
  fail at boot:
  - a test class without its dev dependencies (`composer install --no-dev`): `Class
    "…\TestCase" not found`;
  - a folder nobody may open — a database volume (`./pgdata`), another user's folder, a
    folder renamed by case on a macOS Docker mount: `Failed to open directory`;
  - and a script's top-level code ran at every boot.

  A folder inside the code that cannot be opened is skipped now, and the log says so:
  `Class scan skipped <dir>: it cannot be opened (permissions?). Its classes are not
  registered.` A project with no `autoload` folder stops at boot with the line to add.
- Requires `flytachi/winter-di` 2.3: several scan roots in one pass, `Scanner::composer()`,
  excluded folders are no longer entered, and an exclusion stops at the folder boundary
  (`vendor` no longer hides `vendor-tools/`).

### Fixed

- A stop that arrived during a unit of work (`markBusy()` … `markIdle()`) let the unit finish
  — and then the body's next `sleep()` was slept in full: work done in 5 s, and the process
  sat out its `sleep(60)`, or was forced down by its grace. That sleep is now interrupted
  at once. A spawned task is still drained, not interrupted.
- A container killed outright (`docker kill`, OOM) and recreated on the same mounted tree held
  its components for good: their records named the old container's hostname, and a record
  from another host was taken for a process running there without asking its lock. A record
  from another host whose lock is free is a dead one now, and does not hold its component.

### Upgrading from 4.4.0

1. A class outside the `autoload` folders and outside `bootstrap.php` is no longer found.
   Move it into an autoloaded folder (`main/` in the skeleton), or add its folder to
   `autoload`, then run `composer dump-autoload`.
2. A project without an `autoload` section needs one:
   `"autoload": {"psr-4": {"Main\\": "main/"}}`.
3. A process, scheduler or WebSocket that relied on the old `$grace` of 0 (wait for good)
   gets 30 s now: set `protected float $grace = 0.0;` to keep it. Outside programs get 30 s
   instead of 10 before SIGKILL.
4. In your own `docker-compose.yml`, add `stop_grace_period: 40s`; on Kubernetes, set
   `terminationGracePeriodSeconds: 40`.

## [4.4.0] — 2026-10-30

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

**The application says how it ends.** Next to `Application up: …` at the start, the log now
gets a line when a stop begins and one when it is done:
```
Application stopping (SIGTERM): 4 component(s)
Application stopped in 1.8s
```
A stop caused by a critical component reads `(critical failure)` in both lines, and
`call run dev` reloading after a code change logs `Application reloading.` instead. A
headless application whose components have all finished logs
`Application finished: every component has done its work.`

**SIGHUP reloads the whole application.** `kill -HUP` on the application — `docker kill -s HUP`
— now reaches every component, each reloading in its own way, and stops none: the HTTP
server restarts its workers gracefully (it is sent SIGUSR1; Swoole has no SIGHUP handler),
a Process gets `onReload()`, a scheduler re-reads its schedule, a daemon its `onReload()`
and passes SIGHUP to its workers. An outside program (`command()`, `script()`) gets nothing,
since SIGHUP's default would end it. The log says `Application reloading (SIGHUP): N
component(s)`. Before, SIGHUP went to the HTTP server only, which ignored it. SIGUSR1 and
SIGUSR2 still go to the HTTP server only; a process gets them on its own PID.

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
  `pathStorageCache`, `pathStorageRunnable` and `isTmpVolatile`, and `pathEnv`. The
  application always loaded `<root>/.env` whatever `pathEnv` said — only `call cfg` used
  it — so `.env` is now always `<root>/.env`, and `Kernel::$pathEnv` says so.
  `Kernel::init()` takes `pathRoot` and `pathResource`.
- **From `Kernel`:** the properties `$pathStorage*`. Use `$pathWinter`, `$pathCache`,
  `$pathBuild`, `$pathRunnable` and `$pathLogs` instead.
- **From `KernelStore`:** `store()`, `volatile()`, `showStorages()` and `showVolatiles()`.

### Fixed

- Two projects in folders with the same name shared one class and proxy cache in `/tmp`. Each
  project now has its own cache in `.winter/build/`.
- A fresh project could run without a working process lock, and nothing reported it. The lock
  now creates its own folder.
- `call run --help` (and `-h`, `application start --help`) started the application instead of
  showing the help.
- Ctrl+C on `call run` killed the HTTP server outright. Its components were sent the signal
  the application got, and a Swoole server has no SIGINT handler: requests in flight were
  cut and the workers' pools never closed. Every component now gets SIGTERM on a stop,
  whatever the application received.
- A stop that arrived while the components were being started reached only those already
  running. The rest were started anyway, never heard of the stop, and the application
  waited for them for good — `docker stop` in the first seconds of a container ended in
  SIGKILL. Nothing more is started once a stop has begun, and the stop signals are held
  around each fork so that none is lost or runs in the child.
- An application killed outright on a host — `kill -9`, the OOM killer — left its components
  running: each lives in a process group of its own, and nothing stopped them. An orphaned
  HTTP server kept its port, and since every component had inherited the application's
  lock, the next `call run` refused to start ("already running" for a dead PID) and
  `app status` read RUNNING. The application now forks a small `winter-sentinel` that is
  told the group of every component; when the application dies without stopping them, it
  sends them SIGTERM, SIGKILL after 10 s, and exits — without running the application's
  shutdown functions it inherited. If the sentinel itself dies first, the application logs a
  warning. Components no longer hold the lock.
  (In a container the whole container went with its PID 1, so this showed on hosts only.)
- A component told the supervisor of its stop through an error handler that turns warnings
  into exceptions — the supervisor already gone — died in the signal handler without its
  hooks. A message to the supervisor can no longer fail the component.
- A component stopped on request (`X::stop()`, `call process X stop`) whose body outlasted
  its grace was ended with exit 1 and taken for a crash, so the supervisor started it
  again. The stop is now reported when it is taken, not on the way out.
- A `.winter/` that could not be written — a project folder owned by root, mounted into the
  image that runs as `winter` — failed the start far from the cause: `di build` said a cache
  file "was not produced", and in development the application came up and lost its
  components one by one. The start and `di build` now stop at once, naming the folder and
  the user (`chown -R 1000:1000` the project in that case).
- `HealthIndicator::DEGRADED_LATENCY_MS` could not be overridden: it was read through
  `self::`, so a subclass's value was ignored, unlike the disk and memory thresholds.
- `#[EnableWebSocket]` on a parent class of the application was silently ignored — the
  socket never started — while every other `#[Enable*]` there stops the boot with an
  error. It stops the boot too now.
- `call daemon X status -v` promised the master's CPU and memory and did not print them; it
  does now, and so does `call schedule status -v`.
- A daemon worker that crashed was replaced without a word. The daemon now logs
  `worker#N (PID n) crashed: killed by signal 9; restarting in 1s.` (or why it is not
  restarted).
- Answers to `dispatch()` that the caller stopped waiting for stayed in `.winter/runnable`
  for good; they expire now.
- A host given in brackets (`[::1]`, as in a URL) failed to bind and was printed with two
  pairs of brackets; it is taken as `::1`.
- An outside program (`command()`, `script()`) started with SIGPIPE ignored, inherited from
  PHP's CLI: `producer | head` in a script ended in "Broken pipe" errors. It gets the
  default now.
- A daemon worker still booting died on SIGHUP, now that a reload of the application reaches
  every worker; a worker ignores the reload signals until it sets its own handlers.
- A reload of the scheduler (SIGHUP) dropped the runs in flight: a task still in the new
  schedule could start again while its previous run went on — the one thing a task never
  does. A run now stays with its task across a reload; the run of a task the reload removed
  is waited for, and not started again.
- `call schedule list` and `status` read the tasks from the `#[Scheduled]` scan, so a
  scheduler with its own `discover()` (tasks from a database) showed none. A running
  scheduler now publishes what it runs; one of your own that is not running is named as
  such.
- `call make --mvc` filed an entity under `Utils/` instead of `Entities/`.
- The ASCII fallback of a download's file name (`filename=`, for old clients) replaced every
  byte outside ASCII with `_`, two per Cyrillic letter; it replaces characters now
  (`Договор №14.pdf` → `_______ _14.pdf`).
- `ProcessStatus` as JSON now carries `host` and `managed_by`, as the object does.
- `call daemon --help` listed the worker states without `retired`.
- `#[Inject('name')]` on a parameter of a `#[Bean]` method was ignored: the parameter was
  resolved by its type, so a named bean asked for by name came as another one.
- A bean with a name (`#[Bean(name: …)]`) was reachable by the name only. Injecting its type
  quietly built a new object that was none of the beans. Now, once every bean is known: an
  unnamed bean of the type owns the type; otherwise a lone named bean is its type too; two or
  more named beans make injecting the type an error that names them. A class registered by
  its own `#[Singleton]`/`#[Request]`/`#[Transient]` keeps its type.
- `ServerSettings` suggested `ssl_cert_file` for HTTPS. The server was built without TLS, so
  the handshake failed and it kept serving plain HTTP.

### Upgrading from 4.3

1. Stop the running application before updating. The new kernel no longer reads the records
   the old one kept in `storage/runnable`.
2. In scripts, Docker and process managers, replace `call run --port=N` with
   `call run --http.port=N`, or declare the port in code with `#[EnableHttp(port: N)]`.
3. Replace `LOG_FILE` / `LOG_*_FILE` with `LOG_DIR`, if they were set.
4. Add `/.winter` and `/logs` to `.gitignore`. Remove `storage/` once nothing points at it.
5. Remove the `storage` path arguments and `pathEnv` from any custom `Kernel::init()`
   call. Call it with named arguments (`pathRoot:`, `pathResource:`): a positional call
   written for the old signature would put its second argument into `pathResource`.
6. If the actuator served more than `health`, list those endpoints in
   `#[EnableActuator(expose: [...])]`.
7. Optionally, regenerate the Docker files with `call cfg docker -f` to move to the
   `flytachi/winter` image.

[Unreleased]: https://github.com/flytachi/winter-kernel/compare/v4.4.0...HEAD
[4.4.0]: https://github.com/flytachi/winter-kernel/compare/v4.3.1...v4.4.0
