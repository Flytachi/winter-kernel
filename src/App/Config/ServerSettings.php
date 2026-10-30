<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\BindAddress;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Fluent builder for the Swoole HTTP server options — the replacement for the old
 * `swooleConfig()` hook. The address comes from `#[EnableHttp]` and the launch flags,
 * the tuning from .env (`SERVER_*`); then each {@see WebConfigurer::configureServer()}
 * may change either. The options are passed to `\Swoole\Http\Server::set()`.
 *
 * ```
 * $server->maxRequest(5000)
 *        ->tls(cert: '/etc/ssl/app.crt', key: '/etc/ssl/app.key');
 * ```
 *
 * The worker count is deliberately absent from that example — see {@see workers()}.
 * One worker is the default and the right answer for almost every application, because
 * concurrency here comes from coroutines inside a worker, not from more workers.
 *
 * @link https://winterframe.net/docs/web-configuration Workers, timeouts, limits and memory
 */
final class ServerSettings
{
    /**
     * Seconds a request may run before the watchdog cancels it.
     *
     * Chosen at 30 rather than PHP-FPM's 60: under Swoole a stuck request holds more
     * than itself — a pooled connection is borrowed for the whole request, and the pool
     * is shared by every request in the worker. .NET settles on the same 30.
     *
     * A route that legitimately runs longer says so with #[Timeout]; the global value is
     * there to stop the ones that hang by accident.
     */
    private const float DEFAULT_REQUEST_TIMEOUT = 30.0;

    /**
     * Largest request accepted, in bytes — the whole packet, headers included.
     *
     * Swoole's own limit is **2 MB** — measured, 2 000 KB passes and 2 048 KB comes back
     * `413` — which is tight for anything that accepts a document or an image, and
     * invisible: the client gets a bare status with nothing explaining it. 8 MB matches
     * PHP's own `post_max_size` default, so a PHP developer meets the number they expect.
     *
     * It is not raised further on purpose. The body is held in the worker's heap for the
     * duration of the request, and the heap is shared by every request in flight — 64 MB
     * bodies at a hundred concurrent uploads is 6.4 GB, and the worker dies before the
     * upload does.
     */
    private const int DEFAULT_MAX_REQUEST_SIZE = 8 * 1024 * 1024;

    /** What the `Server` response header says unless told otherwise. */
    public const string DEFAULT_SERVER_HEADER = 'Winter';

    /**
     * Smallest response compressed, in bytes.
     *
     * Swoole's own threshold is about 20 bytes — measured: 14 bytes go out as they are,
     * 28 come back as 37. Below a kilobyte a response fits one TCP packet either way, so
     * compressing it saves the client nothing and costs the worker: measured on a 537-
     * byte JSON, 103 000 requests a second plain against 62 700 compressed. Caddy stops
     * at 512 bytes; nginx guides settle on 1 KB.
     */
    private const int DEFAULT_COMPRESSION_MIN_LENGTH = 1024;

    /**
     * How hard to compress, 1–9 — the same scale for brotli and gzip. Swoole's own
     * default, kept: measured on JSON, level 5 makes responses only 8–10 % smaller and
     * the worker 2–4 times slower.
     */
    private const int DEFAULT_COMPRESSION_LEVEL = 1;

    /** Assumed heap when the limit cannot be read — `-1`, or a value PHP cannot parse. */
    private const int ASSUMED_MEMORY_LIMIT = 128 * 1024 * 1024;

    /** @param array<string, mixed> $options */
    private function __construct(
        private string $host,
        private int $port,
        private array $options = [],
        private ?string $memoryLimit = null,
        private float $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT,
        private ?string $memoryTrimThreshold = null,
        private ?Profile $profile = null,
        private int $baselineBytes = 0,
        private string $serverHeader = self::DEFAULT_SERVER_HEADER,
    ) {
    }

    /**
     * Seeds the bind address and base Swoole options. Host/port are passed in — what
     * `#[EnableHttp]` and `--http.port` / `--http.host` resolved to; tuning options come
     * from the environment — only variables that are actually set contribute a key (so Swoole
     * defaults apply otherwise).
     *
     * Nothing the profile decides is seeded here. Those are resolved when read, so a
     * {@see WebConfigurer} that raises `memoryLimit()` raises everything derived from it
     * no matter which order the two calls are made in.
     */
    public static function fromEnv(string $host = '0.0.0.0', int $port = 8000): self
    {
        $options = [
            'package_max_length' => self::DEFAULT_MAX_REQUEST_SIZE,
            // Swoole parses the `Cookie` header itself by default and, having parsed it,
            // drops it from `$request->header` — the raw header the adapters read to give
            // both runtimes the same cookie names is simply not there. Its own parsing is
            // not FPM's either: `my.sid` arrives as `my_sid` and the last of two same-named
            // cookies wins instead of the first. Off, so `$request->header['cookie']`
            // survives and {@see \Flytachi\Winter\Kernel\Http\Cookie\CookieParser} decides.
            // An application may switch it back on; the adapter degrades rather than breaks.
            'http_parse_cookie' => false,
            'http_compression'            => true,
            'http_compression_level'      => self::DEFAULT_COMPRESSION_LEVEL,
            'http_compression_min_length' => self::DEFAULT_COMPRESSION_MIN_LENGTH,
        ];
        $map = [
            'SERVER_WORKERS'           => 'worker_num',
            'SERVER_TASKS'             => 'task_worker_num',
            'SERVER_MAX_REQUEST'       => 'max_request',
            'SERVER_MAX_REQUEST_GRACE' => 'max_request_grace',
            'SERVER_MAX_REQUEST_SIZE'  => 'package_max_length',
            'SERVER_MAX_CONNECTIONS'   => 'max_connection',
            'SERVER_MAX_CONCURRENCY'   => 'worker_max_concurrency',
            'SERVER_IDLE_TIMEOUT'      => 'heartbeat_idle_time',
        ];
        // env() hands `true`/`false` over as booleans and digits as integers.
        $compression = self::flag(env('SERVER_COMPRESSION'));
        if ($compression !== null) {
            $options['http_compression'] = $compression;
        }
        $level = env('SERVER_COMPRESSION_LEVEL');
        if (is_int($level)) {
            $options['http_compression_level'] = max(1, min(9, $level));
        }
        $minLength = self::byteCount(env('SERVER_COMPRESSION_MIN'));
        if ($minLength !== null) {
            $options['http_compression_min_length'] = $minLength;
        }

        // Where the certificate lives is the deployment's: mounted with the container.
        foreach (['SERVER_TLS_CERT' => 'ssl_cert_file', 'SERVER_TLS_KEY' => 'ssl_key_file'] as $envKey => $swooleKey) {
            $path = env($envKey);
            if (is_string($path) && trim($path) !== '') {
                $options[$swooleKey] = self::projectPath(trim($path));
            }
        }
        $http2 = self::flag(env('SERVER_HTTP2'));
        if ($http2 !== null) {
            $options['open_http2_protocol'] = $http2;
        }

        // `SERVER_MEMORY_LIMIT` is handled below — it is a PHP ini, not a Swoole key.
        foreach ($map as $envKey => $swooleKey) {
            $raw = env($envKey);
            if ($raw !== null && is_numeric($raw)) {
                $options[$swooleKey] = (int) $raw;
            }
        }

        // Not a Swoole option — a PHP ini, so it is carried separately and never
        // reaches Swoole\Server::set(). Kept as written ('256M', '1G', '-1').
        $memoryLimit = env('SERVER_MEMORY_LIMIT');
        $memoryLimit = is_string($memoryLimit) && $memoryLimit !== '' ? $memoryLimit : null;

        $timeout = env('SERVER_REQUEST_TIMEOUT');
        $timeout = is_numeric($timeout) ? max(0.0, (float) $timeout) : self::DEFAULT_REQUEST_TIMEOUT;

        $trim = env('SERVER_MEMORY_TRIM');
        $trim = is_string($trim) && $trim !== '' ? $trim : null;

        $profile = env('SERVER_PROFILE');
        $profile = is_string($profile) ? Profile::tryFrom(strtolower(trim($profile))) : null;


        // Captured once, here, rather than on every read: this runs after bootstrap and
        // before any worker exists, which is exactly the heap a worker starts from, and a
        // value that changed between two reads would make the derived limits disagree
        // with each other.
        return new self($host, $port, $options, $memoryLimit, $timeout, $trim, $profile, memory_get_usage(true), self::serverHeaderFromEnv());
    }

    /**
     * Bind host (e.g. '0.0.0.0', '127.0.0.1'). Overrides `#[EnableHttp(host:)]` and
     * `--http.host`.
     *
     * @throws ApplicationConfigException When it is empty.
     */
    public function host(string $host): self
    {
        if (trim($host) === '') {
            throw new ApplicationConfigException('The HTTP host cannot be empty.');
        }
        $this->host = BindAddress::bare($host);
        return $this;
    }

    /**
     * Bind port. Overrides `#[EnableHttp(port:)]` and `--http.port` — to keep a launch
     * flag in force, leave it alone when one was given.
     *
     * ```
     * // A variable of your own, falling back to what was declared or passed:
     * $server->port((int) env('ORDERS_PORT', $server->getPort()));
     * ```
     *
     * @throws ApplicationConfigException When it is outside 1–65535.
     */
    public function port(int $port): self
    {
        if ($port < 1 || $port > 65535) {
            throw new ApplicationConfigException("The HTTP port must be 1–65535, {$port} given.");
        }
        $this->port = $port;
        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * How many worker **processes** serve requests. Leave it alone unless a measurement
     * says otherwise.
     *
     * The default is **one**, and that is not a conservative placeholder: `Swoole\Http\Server`
     * runs in `SWOOLE_BASE` mode and the framework never sets `worker_num`, so one process
     * starts. It is enough for almost everything, because concurrency in this runtime does
     * not come from processes — a single worker serves hundreds of requests at once through
     * coroutines, yielding on every I/O wait. Adding workers does not add that concurrency;
     * it adds CPU parallelism, which only matters once one core is genuinely saturated by
     * computation rather than by waiting.
     *
     * Scale is normally taken elsewhere. The application runs in a container, and load is
     * met by another **instance** — a Kubernetes replica, a second service in compose,
     * another machine behind the balancer — which also buys fault tolerance and zero-
     * downtime deploys that a second worker in the same container cannot. One container,
     * one worker is the shape this runtime is built for.
     *
     * Raising it belongs to specific applications: work that pins a core by **computing**
     * rather than waiting — image processing, parsing large documents, encryption in the
     * request handler. Such work never yields, so the other requests in that worker wait
     * behind it. (Often the better answer is moving that work into a Process or a Daemon.)
     *
     * The price is paid per worker:
     *
     * - **Memory multiplies.** The limit is per worker — four workers at `256M` need a
     *   gigabyte before opcache. {@see WorkerMemory} says this out loud at boot.
     * - **Connection pools multiply.** Each worker holds its own database and Redis pool,
     *   so `poolMaxConnections` is per worker: 4 workers × 10 connections is 40 sockets
     *   against a server that may cap them.
     * - **Per-worker state is duplicated.** Caches warm separately, telemetry is reported
     *   per worker, and anything held in a process is no longer shared.
     *
     * Pass a literal number you can justify, never a formula derived from the host: how
     * many cores a container may use is set by its limits, not by the machine, and a value
     * computed at startup parts ways with them the first time the quota changes.
     *
     * @param int $count Worker processes, written by hand. `1` unless a measurement shows
     *                   a core pinned by computation.
     *
     * @link https://winterframe.net/docs/web-configuration Why one worker is the default
     */
    public function workers(int $count): self
    {
        return $this->set('worker_num', $count);
    }

    public function taskWorkers(int $count): self
    {
        return $this->set('task_worker_num', $count);
    }

    /**
     * Requests a worker serves before it is replaced by a fresh one. `0` never replaces
     * it. Derived from the profile when not set — see {@see Profile::maxRequest()}.
     *
     * Replacement is not a precaution but a necessity: Swoole leaks 56 bytes on every
     * coroutine suspend/resume, and an HTTP request always suspends.
     */
    public function maxRequest(int $count): self
    {
        return $this->set('max_request', $count);
    }

    /**
     * How far apart workers may drift before recycling. Derived from the profile when not
     * set — a tenth of {@see maxRequest()}.
     *
     * Swoole **adds** a random amount up to this to `max_request`, so the real limit is
     * `max_request + rand(0, grace)`.
     */
    public function maxRequestGrace(int $count): self
    {
        return $this->set('max_request_grace', $count);
    }

    /**
     * The stance this server takes on its own memory — and, through it, every limit that
     * keeps a worker alive. {@see Profile::Balance} when nothing is said.
     *
     * ```
     * $server->profile(Profile::Performance);
     * ```
     *
     * It supplies {@see maxConcurrency()}, {@see maxConnections()}, {@see maxRequest()},
     * {@see maxRequestGrace()} and {@see memoryTrimThreshold()} as **defaults**, resolved
     * when each is read. Anything set explicitly — here, or through a `SERVER_*` variable
     * — wins regardless of the order the calls are made in:
     *
     * ```
     * $server->profile(Profile::Performance)
     *        ->maxConnections(10_000);   // browsers hold connections open; the rest stands
     * ```
     *
     * Choosing one is answering how much memory a single request uses, which is
     * measurable — see {@see Profile}.
     */
    public function profile(Profile $profile): self
    {
        $this->profile = $profile;
        return $this;
    }

    /** The profile in force: the configured one, `SERVER_PROFILE`, or {@see Profile::Balance}. */
    public function getProfile(): Profile
    {
        return $this->profile ?? Profile::Balance;
    }

    /** Requests a worker will serve before replacement; `0` when it is never replaced. */
    public function getMaxRequest(): int
    {
        $configured = $this->options['max_request'] ?? null;

        return is_int($configured) ? $configured : $this->getProfile()->maxRequest($this->limitBytes());
    }

    /** The random spread added to {@see getMaxRequest()}. */
    public function getMaxRequestGrace(): int
    {
        $configured = $this->options['max_request_grace'] ?? null;

        return is_int($configured) ? $configured : $this->getProfile()->maxRequestGrace($this->limitBytes());
    }

    /**
     * Largest request accepted, in bytes — **headers included**. Default: 8 MB.
     *
     * ```
     * $server->maxRequestSize(32 * 1024 * 1024);   // room for 32 MB uploads
     * ```
     *
     * The limit is on the whole packet, not the body alone: at the default, a body of
     * 8 388 400 bytes passes and 8 388 608 does not — the difference is the request's own
     * headers. Leave room for them when sizing an upload endpoint.
     *
     * Swoole's own limit is 2 MB and says nothing when it is hit — the client gets a bare
     * `413`, which sends the reader looking anywhere but here. The default matches PHP's
     * `post_max_size`, so a PHP developer meets the number they expect.
     *
     * Raise it knowing what it costs: the request sits in the worker's heap for its whole
     * life, and that heap is shared by every request in flight. Large bodies and high
     * concurrency multiply.
     */
    public function maxRequestSize(int $bytes): self
    {
        return $this->set('package_max_length', $bytes);
    }

    /**
     * Largest number of simultaneous TCP connections. Derived from the profile when not
     * set — twice {@see getMaxConcurrency()}, the working ones and an idle one apiece.
     *
     * Not the same thing as concurrent requests, though it is easy to read it that way:
     * a keep-alive connection serves many requests one after another, and an idle one
     * serves none. Measured — `max_connection = 2` did not slow six concurrent requests
     * at all, while `worker_max_concurrency = 2` doubled their total time by queueing.
     *
     * It is not free, though. A held connection costs about **68 KB of PHP heap** —
     * measured linearly at 401, 801 and 1 201 connections, and charged to `memory_limit`,
     * not merely to RSS — so a worker with the default 128M dies at roughly 1 900 idle
     * keep-alive connections. Swoole's own 100 000 is therefore no limit at all: it would
     * take 6.8 GB to reach.
     *
     * Raise it for a service whose clients hold connections open while asking nothing —
     * browsers with open tabs, mobile clients polling rarely. The profile assumes one such
     * client per working request, which suits a service behind nginx or one called by
     * other services.
     */
    public function maxConnections(int $count): self
    {
        return $this->set('max_connection', $count);
    }

    /** Connections allowed at once: the configured value, or the profile's derivation. */
    public function getMaxConnections(): int
    {
        $configured = $this->options['max_connection'] ?? null;
        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        return $this->getProfile()->connections($this->availableBytes(), $this->descriptorLimit());
    }

    /**
     * The process's file-descriptor ceiling, or {@see PHP_INT_MAX} when there is none to
     * read — a socket is a descriptor, so this bounds connections independently of memory.
     *
     * The soft limit, because that is the one in force and the one Swoole clamps to. It is
     * often the tighter of the two ceilings on a developer's own machine: measured, a
     * container from the same image allows 1 048 576, while a macOS shell allows 1 024.
     */
    private function descriptorLimit(): int
    {
        if (!function_exists('posix_getrlimit')) {
            return PHP_INT_MAX;
        }
        $limit = posix_getrlimit()['soft openfiles'] ?? null;

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : PHP_INT_MAX;
    }

    /**
     * Seconds a connection may go without sending data before the server closes it.
     * Off by default, which is Swoole's own behaviour.
     *
     * ```
     * $server->idleConnectionTimeout(300);   // must exceed the longest request
     * ```
     *
     * **It also cuts requests that are still running**, and this is the whole reason it is
     * off. Swoole measures the time since the client last *sent* something, and a client
     * waiting for a slow response sends nothing — so a request being worked on looks
     * exactly like an abandoned connection. Measured: with the timeout at 2 seconds a
     * 5-second request was cut at 2.07 s and the client got no response; at 8 seconds the
     * same request returned normally at 5.00 s. Nothing was written to the log either
     * time — the connection simply ends.
     *
     * So it is a ceiling on request duration as much as on idleness, and it would silently
     * overrule {@see \Flytachi\Winter\Kernel\Route\Annotation\Timeout} — a route allowed
     * ten minutes would still die here. Set it above the longest request the application
     * permits, and prefer {@see requestTimeout()} for bounding request duration: that one
     * cancels the coroutine, so `finally` runs, connections return to the pool, and the
     * client is told what happened with a `504`.
     *
     * What it does buy: a client that opens a connection and vanishes otherwise holds a
     * file descriptor for the life of the worker. That is a small prize — the connection
     * table costs nothing measurable (1 024 vs 1 000 000 connections differ by 160 KB of
     * RSS) and containers here allow about a million descriptors — which is why it does
     * not pay for the hazard by default.
     *
     * A related detail worth knowing, since the two are usually mentioned together:
     * `heartbeat_check_interval` is **not** required for this to work — verified, the
     * timeout closes idle connections on its own.
     */
    public function idleConnectionTimeout(int $seconds): self
    {
        return $this->set('heartbeat_idle_time', $seconds);
    }

    /**
     * Largest number of requests one worker processes at the same time. Derived from the
     * profile when not set — see {@see getMaxConcurrency()}.
     *
     * ```
     * $server->maxConcurrency(10000);   // a proxy: requests are cheap, mostly waiting
     * ```
     *
     * Swoole **queues** what exceeds it rather than refusing — measured: twenty concurrent
     * 0.3-second requests against a limit of 2 all succeeded, taking 3.3 seconds in total
     * instead of 0.3. So overload turns into latency, not errors, and no client is turned
     * away because the server is briefly busy.
     *
     * This is the setting that actually protects the worker. `memory_limit` decides when
     * it dies; this decides whether it gets there. Not to be confused with
     * {@see maxConnections()}, which counts sockets: a keep-alive connection serves many
     * requests in turn and an idle one serves none.
     *
     * It is not a rate limit, and cannot be used as one. It has no idea who is calling, so
     * it cannot give one client 50 requests a second and another 20 — and it delays rather
     * than rejects, where a quota has to answer `429`. A per-client quota also has to be
     * shared between workers, which a per-worker number never is.
     */
    public function maxConcurrency(int $count): self
    {
        return $this->set('worker_max_concurrency', $count);
    }

    /**
     * The concurrency ceiling that will be applied: the configured value, or the
     * profile's derivation from the heap left after the worker's own baseline.
     *
     * At 256M with {@see Profile::Balance}, 896 in flight; at 512M, 1 853. Doubling the
     * limit doubles the ceiling — but only turns into throughput if the ceiling was what
     * bound. `worker_concurrency` in `Swoole\Server::stats()` says whether it was:
     * sitting at the ceiling means requests are queueing, well below it means the
     * bottleneck is somewhere else.
     */
    public function getMaxConcurrency(): int
    {
        $configured = $this->options['worker_max_concurrency'] ?? null;
        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        // Never more than there are connections to carry them: a request in flight holds
        // a socket, so a descriptor ceiling below the memory one binds here too.
        return min($this->getProfile()->concurrency($this->availableBytes()), $this->getMaxConnections());
    }

    /**
     * The worker's memory ceiling in bytes, or {@see ASSUMED_MEMORY_LIMIT} when it cannot
     * be read (`-1`, or a value PHP cannot parse) — an application that opts out of
     * memory limits still gets limits derived, rather than none.
     */
    private function limitBytes(): int
    {
        $bytes = WorkerMemory::bytes($this->memoryLimit ?? (string) ini_get('memory_limit'));

        return $bytes > 0 ? $bytes : self::ASSUMED_MEMORY_LIMIT;
    }

    /**
     * Heap the requests may actually share: the limit less what the application already
     * holds before serving anything.
     *
     * The baseline is **measured, not assumed** — an application with a hundred routes,
     * three connection pools and a wide dependency graph starts heavier than an empty
     * one, and gets a correspondingly smaller ceiling without being asked. It is taken in
     * the master, which has run the same bootstrap the workers inherit; a worker allocates
     * a little more of its own (its pool fills lazily), so this errs slightly generous.
     */
    private function availableBytes(): int
    {
        return max(0, $this->limitBytes() - $this->baselineBytes);
    }

    /**
     * Serves static files from `$path` using Swoole's own handler.
     *
     * Static content is opt-in: say nothing here and no file is ever served, which is
     * what an API-only service wants. Swoole answers these requests in C, before PHP
     * is involved — it streams the file instead of reading it into the worker, honours
     * `Range`, and cannot be walked out of the directory with `..`.
     *
     * ```
     * $server->staticPath('resources/static');   // resources/static/app.css → /app.css
     * ```
     *
     * The directory *is* the URL root: Swoole appends the whole request path to it, so
     * the layout on disk mirrors the layout in URLs. Point it at a directory that holds
     * assets and nothing else — every file under it becomes downloadable.
     *
     * Because those requests never reach PHP, middleware, CORS and request logging do
     * not apply to them.
     *
     * Swoole checks the filesystem for each request to decide whether it is a static
     * one. To limit that to certain prefixes, set the underlying option directly:
     * `->set('static_handler_locations', ['/assets'])`.
     *
     * @param string $path Directory to serve from; relative paths resolve against the
     *   project root.
     * @throws ApplicationConfigException When the directory does not exist — a typo
     *   here would otherwise surface as silent 404s at runtime.
     */
    public function staticPath(string $path): self
    {
        $dir = rtrim(self::projectPath($path), '/\\');

        if (!is_dir($dir)) {
            throw new ApplicationConfigException("Static directory does not exist: {$dir}");
        }

        return $this->set('document_root', $dir)
            ->set('enable_static_handler', true);
    }

    /**
     * Memory ceiling for each worker process, applied on worker start.
     *
     * ```
     * $server->workers(4)->memoryLimit('256M');
     * ```
     *
     * Say nothing and the framework does not touch the setting at all — PHP's own
     * value stands (128M compiled in, unless a php.ini raises it). Nothing existing
     * changes by upgrading.
     *
     * **This is a PHP ini value, not a Swoole option**, so it never reaches
     * `Swoole\Server::set()` — see {@see toArray()}. It lives here because the limit
     * is only half of an arithmetic whose other half, `worker_num`, is already set
     * on this object: a box has to hold `worker_num × memoryLimit` plus opcache's
     * shared memory, and keeping the two apart is how that product goes unnoticed
     * until a worker dies.
     *
     * The limit is **per worker, shared by every coroutine in it** — a Swoole worker
     * serves many requests at once out of one heap, so this bounds their sum, not any
     * single request. Raising it moves the threshold; it does not remove it. What
     * stops a worker dying is bounding concurrency, not the ceiling.
     *
     * `-1` (unlimited) is accepted but warned about at boot: without a limit PHP never
     * stops, so the process grows until the kernel's OOM killer sends SIGKILL — no
     * shutdown functions, no log line, and the whole container when the server is
     * PID 1. A limit at least fails through PHP, which the manager can recover from.
     *
     * @param string $limit A PHP memory value: '256M', '1G', or '-1' for unlimited.
     */
    public function memoryLimit(string $limit): self
    {
        $this->memoryLimit = $limit;
        return $this;
    }

    /** The configured per-worker memory limit, or null when the ini is left alone. */
    public function getMemoryLimit(): ?string
    {
        return $this->memoryLimit;
    }

    /**
     * How long a request may run before the server stops waiting for it, in seconds.
     * `0` disables the deadline. Default: 30.
     *
     * ```
     * $server->requestTimeout(60);   // globally
     * $server->requestTimeout(0);    // no deadline at all
     * ```
     *
     * Individual routes override it with
     * {@see \Flytachi\Winter\Kernel\Route\Annotation\Timeout} — a report that legitimately
     * takes ten minutes carries `#[Timeout(600)]`, and the global value protects
     * everything else.
     *
     * Swoole has no request timeout of its own — verified against the extension: no
     * option in its lists concerns execution time, and `max_request_execution_time` is
     * absent from the binary and does nothing when set. The deadline is enforced by
     * {@see \Flytachi\Winter\Kernel\Route\RequestWatchdog}, which cancels the request's
     * coroutine; `finally` and `defer` run, so transactions close and pooled connections
     * return, and the client receives `504`.
     *
     * It interrupts a request that **waits**. A request burning CPU is not interrupted —
     * the event loop is single-threaded, so nothing else in the worker runs, the watchdog
     * included. See the watchdog's docblock for the full picture.
     */
    public function requestTimeout(float $seconds): self
    {
        $this->requestTimeout = max(0.0, $seconds);
        return $this;
    }

    /**
     * How long a stop waits for the requests in flight, in whole seconds: as long as a
     * request may run ({@see requestTimeout()}), 30 when requests have no deadline.
     * Swoole's own default is 3 seconds, so a stop cut off any request longer than
     * that — the client got a dropped connection. `->set('max_wait_time', …)` sets it
     * apart. A route with a longer `#[Timeout]` is still cut at this value on a stop.
     */
    public function getStopWait(): int
    {
        if (isset($this->options['max_wait_time'])) {
            return (int) $this->options['max_wait_time'];
        }
        return $this->requestTimeout > 0 ? (int) ceil($this->requestTimeout) : 30;
    }

    /** The configured request deadline in seconds; `0.0` when disabled. */
    public function getRequestTimeout(): float
    {
        return $this->requestTimeout;
    }

    /**
     * How much idle memory a worker may hold before handing it back to the operating
     * system, checked after each request. Default: 32M. `0` never hands anything back.
     *
     * ```
     * $server->memoryLimit('256M')->memoryTrimThreshold('64M');
     * ```
     *
     * PHP releases memory to its own allocator, not to the kernel: many small objects
     * live in chunks that are kept for reuse, so a worker that once built a large result
     * goes on holding that memory for the rest of its life. On a host running several
     * containers that is first-come-first-served — measured, a single request that built
     * 600 000 objects left 258 MB reserved and unused.
     *
     * The threshold is compared against the **reserve** — what the allocator has taken
     * from the kernel minus what is actually in use — so a busy worker is never trimmed
     * (its memory is in use, the reserve is small) and one trim is enough (releasing
     * closes the gap). 32M is far above the two or three megabytes an ordinary worker
     * carries, and far below anything worth keeping.
     *
     * Handing memory back costs about 80 ms when there is a lot of it, and 5 µs when
     * there is none — which is what an ordinary request pays. The next large allocation
     * pays roughly 10 % more, having to take fresh chunks.
     *
     * Derived from the profile when not set, as a fraction of the memory limit — what
     * counts as "suspiciously unused" depends on how much there is.
     *
     * @param string $bytes A PHP memory value: '32M', '512K', or '0' to disable.
     */
    public function memoryTrimThreshold(string $bytes): self
    {
        $this->memoryTrimThreshold = $bytes;
        return $this;
    }

    /** The idle-memory threshold in bytes: the configured value, or the profile's. */
    public function getMemoryTrimThreshold(): int
    {
        if ($this->memoryTrimThreshold !== null) {
            return max(0, WorkerMemory::bytes($this->memoryTrimThreshold));
        }

        return $this->getProfile()->trimThreshold($this->limitBytes());
    }

    /**
     * What every response says in its `Server` header. Default: `Winter`, from
     * `SERVER_HEADER` when set. An empty string sends no header at all.
     *
     * ```
     * $server->serverHeader('Orders API');
     * $server->serverHeader('');   // say nothing about the server
     * ```
     *
     * Without it Swoole sends `swoole-http-server`, which names the runtime to anyone who
     * asks. No version is sent by default: a version tells a scanner which known flaws to
     * try. A response may still set its own `Server` header — it replaces this one.
     *
     * @link https://winterframe.net/docs/web-configuration The Server header
     */
    public function serverHeader(string $value): self
    {
        $this->serverHeader = trim($value);
        return $this;
    }

    /** The `Server` header's value; empty when none is sent. */
    public function getServerHeader(): string
    {
        return $this->serverHeader;
    }

    /**
     * The `Server` header the environment asks for: `SERVER_HEADER`, or `Winter`
     * when it is not set. Shared by the HTTP server and every WebSocket.
     *
     * @internal
     */
    public static function serverHeaderFromEnv(): string
    {
        // Set but empty is an answer of its own: no header at all. env() turns `false`
        // into a boolean — read as "none" too — and digits into an integer.
        $value = env('SERVER_HEADER');
        return match (true) {
            $value === null, $value === true => self::DEFAULT_SERVER_HEADER,
            $value === false => '',
            default => trim((string) $value),
        };
    }

    /**
     * Whether responses are compressed. Default: on — brotli for browsers, gzip or
     * deflate for the rest, whichever the client's `Accept-Encoding` names first among
     * what Swoole was built with.
     *
     * ```
     * $server->compression(false);   // nginx or a CDN in front compresses already
     * ```
     *
     * Compressing is CPU work the worker does without yielding: while a large response
     * is compressed, other requests in that worker wait — 1–2 ms for 400 KB at
     * level 1, microseconds for a typical API response. Files sent by
     * {@see \Flytachi\Winter\Kernel\Http\Response\ResponseStreamFile} are never
     * compressed: their length has to match the file.
     *
     * @link https://winterframe.net/docs/web-configuration Compression
     */
    public function compression(bool $enabled): self
    {
        return $this->set('http_compression', $enabled);
    }

    /**
     * How hard to compress, from 1 (fastest) to 9 (smallest). Default: 1.
     *
     * Measured on JSON: level 5 is 8–10 % smaller and 2–4 times slower than level 1; past
     * that the size barely moves. Worth raising only when bandwidth costs more than CPU.
     *
     * @throws ApplicationConfigException When the level is outside 1–9.
     */
    public function compressionLevel(int $level): self
    {
        if ($level < 1 || $level > 9) {
            throw new ApplicationConfigException("Compression level must be 1–9, {$level} given.");
        }
        return $this->set('http_compression_level', $level);
    }

    /**
     * Smallest response compressed. Default: 1K — a smaller response fits one TCP packet
     * anyway, so compressing it costs CPU and saves the client nothing.
     *
     * ```
     * $server->compressionMinLength('4K');
     * ```
     *
     * @param int|string $bytes Bytes, or a PHP memory value: '512', '1K', '2M'.
     * @throws ApplicationConfigException When the value is not a size.
     */
    public function compressionMinLength(int|string $bytes): self
    {
        $count = self::byteCount($bytes);
        if ($count === null) {
            throw new ApplicationConfigException("Not a size: {$bytes}.");
        }
        return $this->set('http_compression_min_length', $count);
    }

    /** Whether responses are compressed. */
    public function isCompressionEnabled(): bool
    {
        return (bool) ($this->options['http_compression'] ?? true);
    }

    /** The compression level, 1–9. */
    public function getCompressionLevel(): int
    {
        return (int) ($this->options['http_compression_level'] ?? self::DEFAULT_COMPRESSION_LEVEL);
    }

    /** The smallest response compressed, in bytes. */
    public function getCompressionMinLength(): int
    {
        return (int) ($this->options['http_compression_min_length'] ?? self::DEFAULT_COMPRESSION_MIN_LENGTH);
    }

    /**
     * Serves HTTPS: the server speaks TLS on its port, and only TLS — a plain `http://`
     * request to it gets no answer. From `SERVER_TLS_CERT` / `SERVER_TLS_KEY` by default.
     *
     * ```
     * $server->tls(cert: '/etc/ssl/app.crt', key: '/etc/ssl/app.key');
     * ```
     *
     * Usually a proxy in front ends TLS — nginx, Traefik, an ingress, a cloud load
     * balancer — and renews the certificate there; this is for an application that is
     * reached directly. The files are read when the server starts: a renewed certificate
     * takes effect on the next start. TLS 1.2 and 1.3 are accepted, older versions are
     * not (OpenSSL's defaults, measured); `->set('ssl_protocols', …)` narrows that.
     *
     * Both files are checked at boot: a missing or unreadable one stops it there,
     * instead of the server coming up without TLS.
     *
     * @param string $cert The certificate in PEM, the chain after it; relative to the
     *   project root unless absolute.
     * @param string $key Its private key in PEM, unencrypted.
     *
     * @link https://winterframe.net/docs/web-configuration#https HTTPS
     */
    public function tls(string $cert, string $key): self
    {
        $this->options['ssl_cert_file'] = self::projectPath($cert);
        $this->options['ssl_key_file'] = self::projectPath($key);
        return $this;
    }

    /** Whether the server speaks TLS — a certificate was given, here or with `set()`. */
    public function isTls(): bool
    {
        return is_string($this->options['ssl_cert_file'] ?? null) && $this->options['ssl_cert_file'] !== '';
    }

    /** The certificate file, or null without TLS. */
    public function getTlsCert(): ?string
    {
        return $this->isTls() ? (string) $this->options['ssl_cert_file'] : null;
    }

    /**
     * Refuses a TLS setup the server could not start with, before anything binds: half
     * of it given, a file that is not there or not readable, or a Swoole built without
     * OpenSSL. Without the check a mistake here shows up as a server that does not
     * answer — or, before TLS was supported, as one quietly serving plain HTTP.
     *
     * @internal Called at boot.
     * @throws ApplicationConfigException
     */
    public function assertTls(): void
    {
        $cert = $this->options['ssl_cert_file'] ?? null;
        $key = $this->options['ssl_key_file'] ?? null;
        if (($cert === null || $cert === '') && ($key === null || $key === '')) {
            return;
        }
        if ($cert === null || $cert === '' || $key === null || $key === '') {
            throw new ApplicationConfigException(
                'TLS needs both files: the certificate (SERVER_TLS_CERT) and its key (SERVER_TLS_KEY).'
            );
        }
        self::assertTlsFiles($cert, $key);
    }

    /**
     * Refuses a certificate pair a TLS server could not start with: a file that is not
     * there or not readable, one that is not PEM, a key of another certificate, or a
     * Swoole built without OpenSSL. Shared by the HTTP server and
     * {@see \Flytachi\Winter\Kernel\WebSocket\WebSocket}.
     *
     * @param string $cert The certificate file, already resolved.
     * @param string $key Its key file, already resolved.
     *
     * @internal
     * @throws ApplicationConfigException
     */
    public static function assertTlsFiles(string $cert, string $key): void
    {
        foreach (['certificate' => $cert, 'key' => $key] as $what => $file) {
            if (!is_file($file) || !is_readable($file)) {
                throw new ApplicationConfigException("The TLS {$what} cannot be read: {$file}");
            }
        }
        // With ext-openssl at hand, a file that is no certificate or a key that is not
        // its pair is named here; Swoole would only say the server failed to start.
        if (function_exists('openssl_x509_read')) {
            $x509 = @openssl_x509_read((string) file_get_contents($cert));
            if ($x509 === false) {
                throw new ApplicationConfigException("Not a PEM certificate: {$cert}");
            }
            $private = @openssl_pkey_get_private((string) file_get_contents($key));
            if ($private === false) {
                throw new ApplicationConfigException("Not an unencrypted PEM private key: {$key}");
            }
            if (!openssl_x509_check_private_key($x509, $private)) {
                throw new ApplicationConfigException("The TLS key does not belong to the certificate: {$key}");
            }
        }
        // Without Swoole at all the boot stops on that first, with its own message.
        if (extension_loaded('swoole') && !defined('SWOOLE_SSL')) {
            throw new ApplicationConfigException('TLS needs Swoole built with OpenSSL (--enable-openssl).');
        }
    }

    /**
     * Speaks HTTP/2 to the clients that ask for it, HTTP/1.1 to the rest. Off by
     * default; `SERVER_HTTP2=true` switches it on.
     *
     * Browsers use HTTP/2 over TLS only, so for them it goes with {@see tls()}. Without
     * TLS it is cleartext HTTP/2 (h2c) — what a proxy or a gRPC client may speak to a
     * backend.
     */
    public function http2(bool $enabled = true): self
    {
        return $this->set('open_http2_protocol', $enabled);
    }

    /** Whether HTTP/2 is on. */
    public function isHttp2(): bool
    {
        return (bool) ($this->options['open_http2_protocol'] ?? false);
    }

    /**
     * A path as written in config: absolute, or relative to the project root.
     *
     * @internal
     */
    public static function projectPath(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : rtrim((string) Kernel::$pathRoot, '/\\') . '/' . ltrim($path, '/\\');
    }

    /** `true` / `false` / `on` / `off` / `1` / `0` from env(), which turns some into scalars; null for anything else. */
    private static function flag(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value), is_int($value) => (bool) $value,
            is_string($value) => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            default => null,
        };
    }

    /** A size in bytes from an integer or '512' / '1K' / '2M'; null when it is none. */
    private static function byteCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^\s*\d+\s*[KMG]?\s*$/i', $value)) {
            return null;
        }
        return WorkerMemory::bytes($value);
    }

    /** Set any raw Swoole option. */
    public function set(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * The Swoole options only.
     *
     * `memoryLimit` is deliberately absent: it is a PHP ini value, and Swoole answers
     * an option it does not know with `unsupported option` on every start.
     *
     * The profile's four Swoole settings are filled in here rather than in
     * {@see fromEnv()} because they derive from the memory limit, which a
     * {@see WebConfigurer} may still change. A profile that caps nothing
     * ({@see Profile::Stress}) contributes no key, leaving Swoole's own behaviour.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $derived = array_filter([
            'worker_max_concurrency' => $this->getMaxConcurrency(),
            'max_connection'         => $this->getMaxConnections(),
            'max_request'            => $this->getMaxRequest(),
            'max_request_grace'      => $this->getMaxRequestGrace(),
            'max_wait_time'          => $this->getStopWait(),
        ], static fn(int $value): bool => $value > 0);

        return $this->options + $derived;
    }
}
