<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App;

use Flytachi\Winter\K2\Schedule\Scheduler;

/**
 * A declared unit of an {@see \Flytachi\Winter\K2\Application} — the "what my app
 * contains" manifest, in the spirit of a Spring bean / @Enable* switch.
 *
 * Build entries with the named factories, never the constructor:
 * ```
 * protected static function components(): array
 * {
 *     return [
 *         Component::http(port: 8000),
 *         Component::process(KernelSys::class),
 *         Component::daemon(Emails::class),
 *         Component::scheduler(),
 *     ];
 * }
 * ```
 *
 * Under Swoole all entries live in one process (the Http one becomes the server;
 * the others are supervised via addProcess). Under FPM only the Http entry runs
 * (per request) — the rest are launched as standalone `call` processes.
 */
final readonly class Component
{
    /**
     * @param class-string|null $class Process/Daemon/Scheduler class, or a WebSocket handler
     * @param string $host Bind host (Http)
     * @param int $port Bind port (Http)
     * @param string|null $path Mount path (WebSocket)
     */
    private function __construct(
        public ComponentKind $kind,
        public ?string $class = null,
        public string $host = '0.0.0.0',
        public int $port = 8000,
        public ?string $path = null,
    ) {
    }

    /** The main HTTP server — controllers under the app root are scanned automatically. */
    public static function http(string $host = '0.0.0.0', int $port = 8000): self
    {
        return new self(ComponentKind::Http, host: $host, port: $port);
    }

    /**
     * A WebSocket endpoint mounted on the server at $path.
     *
     * @param class-string $handler
     */
    public static function websocket(string $path, string $handler): self
    {
        return new self(ComponentKind::WebSocket, class: $handler, path: $path);
    }

    /**
     * A single managed {@see \Flytachi\Winter\K2\Process\Process} worker.
     *
     * @param class-string<\Flytachi\Winter\K2\Process\Process> $class
     */
    public static function process(string $class): self
    {
        return new self(ComponentKind::Process, class: $class);
    }

    /**
     * A supervised {@see \Flytachi\Winter\K2\Process\Daemon\Daemon} fleet.
     *
     * @param class-string<\Flytachi\Winter\K2\Process\Daemon\Daemon> $class
     */
    public static function daemon(string $class): self
    {
        return new self(ComponentKind::Daemon, class: $class);
    }

    /**
     * The scheduler that runs #[Scheduled] tasks. Defaults to the built-in
     * {@see Scheduler}; pass a subclass to override discovery.
     *
     * @param class-string<Scheduler> $class
     */
    public static function scheduler(string $class = Scheduler::class): self
    {
        return new self(ComponentKind::Scheduler, class: $class);
    }
}
