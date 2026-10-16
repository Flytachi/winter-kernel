<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;

/**
 * A declared unit of a {@see \Flytachi\Winter\Kernel\WinterApplication} — the "what my app
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
     * @param class-string|null $class Process/Daemon/Scheduler/WebSocket class
     * @param string $host Bind host (Http, WebSocket)
     * @param int $port Bind port (Http, WebSocket)
     */
    private function __construct(
        public ComponentKind $kind,
        public ?string $class = null,
        public string $host = '0.0.0.0',
        public int $port = 8000,
    ) {
    }

    /** The main HTTP server — controllers under the app root are scanned automatically. */
    public static function http(string $host = '0.0.0.0', int $port = 8000): self
    {
        return new self(ComponentKind::Http, host: $host, port: $port);
    }

    /**
     * A {@see \Flytachi\Winter\Kernel\WebSocket\WebSocket} server — a process of its own
     * listening on its own port.
     *
     * @param class-string<\Flytachi\Winter\Kernel\WebSocket\WebSocket> $class
     */
    public static function websocket(string $class, int $port, string $host = '0.0.0.0'): self
    {
        return new self(ComponentKind::WebSocket, class: $class, host: $host, port: $port);
    }

    /**
     * A single managed {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process} worker.
     *
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Process> $class
     */
    public static function process(string $class): self
    {
        return new self(ComponentKind::Process, class: $class);
    }

    /**
     * A supervised {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} fleet.
     *
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Daemon> $class
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
