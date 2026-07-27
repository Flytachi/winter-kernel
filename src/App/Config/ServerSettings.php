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
    private function __construct(private array $options = [])
    {
    }

    /**
     * Seeds base options from the environment. Only variables that are actually
     * set contribute a key (so Swoole defaults apply otherwise).
     */
    public static function fromEnv(): self
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
        return new self($options);
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
