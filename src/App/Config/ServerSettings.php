<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Config;

use Flytachi\Winter\K2\App\ApplicationConfigException;
use Flytachi\Winter\K2\Kernel;

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
    /** @param array<string, mixed> $options */
    private function __construct(
        private string $host,
        private int $port,
        private array $options = [],
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
        foreach ($map as $envKey => $swooleKey) {
            $raw = env($envKey);
            if ($raw !== null && is_numeric($raw)) {
                $options[$swooleKey] = (int) $raw;
            }
        }
        return new self($host, $port, $options);
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

    /** Set any raw Swoole option. */
    public function set(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->options;
    }
}
