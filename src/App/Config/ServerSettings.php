<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Fluent builder for the Swoole HTTP server options — the replacement for the old
 * `swooleConfig()` hook. Base values come from .env (`SERVER_*`), then each
 * {@see WebConfigurer::configureServer()} may tune them further; the result is
 * passed to `\Swoole\Http\Server::set()`.
 *
 * ```
 * $server->workers(swoole_cpu_num() * 2)
 *        ->maxRequest(5000)
 *        ->set('ssl_cert_file', '/etc/ssl/app.pem');
 * ```
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

    /** @param array<string, mixed> $options */
    private function __construct(
        private string $host,
        private int $port,
        private array $options = [],
        private ?string $memoryLimit = null,
        private float $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT,
    ) {
    }

    /**
     * Seeds the bind address and base Swoole options. Host/port are passed in (the
     * framework's default policy is `--host`/`--port`); tuning options come from the
     * environment — only variables that are actually set contribute a key (so Swoole
     * defaults apply otherwise).
     */
    public static function fromEnv(string $host = '0.0.0.0', int $port = 8000): self
    {
        $options = [];
        $map = [
            'SERVER_WORKERS'           => 'worker_num',
            'SERVER_TASKS'             => 'task_worker_num',
            'SERVER_MAX_REQUEST'       => 'max_request',
            'SERVER_MAX_REQUEST_GRACE' => 'max_request_grace',
        ];
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

        return new self($host, $port, $options, $memoryLimit, $timeout);
    }

    /** Bind host (e.g. '0.0.0.0', '127.0.0.1'). */
    public function host(string $host): self
    {
        $this->host = $host;
        return $this;
    }

    /** Bind port. */
    public function port(int $port): self
    {
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

    public function workers(int $count): self
    {
        return $this->set('worker_num', $count);
    }

    public function taskWorkers(int $count): self
    {
        return $this->set('task_worker_num', $count);
    }

    public function maxRequest(int $count): self
    {
        return $this->set('max_request', $count);
    }

    public function maxRequestGrace(int $count): self
    {
        return $this->set('max_request_grace', $count);
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
        $dir = str_starts_with($path, '/')
            ? $path
            : rtrim(Kernel::$pathRoot, '/\\') . '/' . ltrim($path, '/\\');
        $dir = rtrim($dir, '/\\');

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

    /** The configured request deadline in seconds; `0.0` when disabled. */
    public function getRequestTimeout(): float
    {
        return $this->requestTimeout;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->options;
    }
}
