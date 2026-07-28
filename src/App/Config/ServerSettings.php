<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Config;

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
