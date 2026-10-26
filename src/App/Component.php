<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;

/**
 * A declared unit of a {@see \Flytachi\Winter\Kernel\WinterApplication} — the "what my app
 * contains" manifest. Declared with the #[Enable*] attributes, or in code with
 * {@see \Flytachi\Winter\Kernel\WinterApplication::components()}; built with the named
 * factories, never the constructor.
 *
 * The Http entry becomes the server; every other one runs in a process of its own under
 * the application's supervisor ({@see \Flytachi\Winter\Kernel\App\Supervision\Supervisor}).
 */
final readonly class Component
{
    /** Restarts a component gets after it crashed, within {@see RETRY_WINDOW}. */
    public const int RETRIES = 3;
    /** Seconds a component has to become ready. */
    public const int START_TIMEOUT = 60;
    /** Seconds over which restarts are counted: a component up this long starts afresh. */
    public const int RETRY_WINDOW = 1800;

    /**
     * @param class-string|null $class Process/Daemon/Scheduler/WebSocket class
     * @param string $host Bind host (Http, WebSocket)
     * @param int $port Bind port (Http, WebSocket)
     * @param bool $critical Whether the application stops when this component cannot run.
     * @param int $retries Restarts after a crash within {@see RETRY_WINDOW}; 0 for a daemon.
     * @param int $startTimeout Seconds to become ready; 0 waits for good.
     * @param list<string> $command The program and its arguments (Command).
     * @param string|null $label The name logs and the banner give it (Command).
     * @param float $grace Seconds an outside program has to stop before SIGKILL (Command).
     */
    private function __construct(
        public ComponentKind $kind,
        public ?string $class = null,
        public string $host = '0.0.0.0',
        public int $port = 8000,
        public bool $critical = true,
        public int $retries = self::RETRIES,
        public int $startTimeout = self::START_TIMEOUT,
        public array $command = [],
        public ?string $label = null,
        public float $grace = 10.0,
    ) {
        if ($retries < 0 || $startTimeout < 0) {
            throw new \InvalidArgumentException('retries and startTimeout cannot be negative.');
        }
        if ($grace < 0) {
            throw new \InvalidArgumentException('grace cannot be negative.');
        }
    }

    /** How logs and the banner name it: the class's short name, or the program's name. */
    public function name(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }
        $class = (string) $this->class;
        $at = strrpos($class, '\\');
        return $at === false ? $class : substr($class, $at + 1);
    }

    /**
     * An outside program the application starts and watches — a script, a binary, a
     * tool written in another language. It is ready as soon as it starts; it is never
     * restarted: exit 0 means its work is done, any other exit that it failed. Stopped
     * with SIGTERM, then SIGKILL after `$grace` seconds. Its output goes to the
     * application's.
     *
     * The program is looked up on PATH, and runs in the project's root with the
     * application's environment.
     *
     * @param list<string> $command The program and its arguments: `['node', 'bridge.js']`.
     * @param string|null $name How logs name it; the program's name by default.
     * @link https://winterframe.net/docs/components#command Outside programs
     */
    public static function command(
        array $command,
        ?string $name = null,
        bool $critical = true,
        float $grace = 10.0,
    ): self {
        $command = array_values($command);
        $strings = array_filter($command, static fn($part): bool => is_string($part)) === $command;
        if ($command === [] || !$strings || $command[0] === '') {
            throw new \InvalidArgumentException(
                'a command is a non-empty list of strings: the program, then its arguments.'
            );
        }
        return new self(
            ComponentKind::Command,
            critical: $critical,
            retries: 0,
            startTimeout: 0,
            command: $command,
            label: $name ?? basename($command[0]),
            grace: $grace,
        );
    }

    /** The main HTTP server — controllers under the app root are scanned automatically. */
    public static function http(
        string $host = '0.0.0.0',
        int $port = 8000,
        bool $critical = true,
        int $retries = self::RETRIES,
        int $startTimeout = self::START_TIMEOUT,
    ): self {
        return new self(
            ComponentKind::Http,
            host: $host,
            port: $port,
            critical: $critical,
            retries: $retries,
            startTimeout: $startTimeout,
            label: 'Http',
        );
    }

    /**
     * A {@see \Flytachi\Winter\Kernel\WebSocket\WebSocket} server — a process of its own
     * listening on its own port.
     *
     * @param class-string<\Flytachi\Winter\Kernel\WebSocket\WebSocket> $class
     */
    public static function websocket(
        string $class,
        int $port,
        string $host = '0.0.0.0',
        bool $critical = true,
        int $retries = self::RETRIES,
        int $startTimeout = self::START_TIMEOUT,
    ): self {
        return new self(ComponentKind::WebSocket, $class, $host, $port, $critical, $retries, $startTimeout);
    }

    /**
     * A single managed {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process} worker.
     *
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Process> $class
     */
    public static function process(
        string $class,
        bool $critical = true,
        int $retries = self::RETRIES,
        int $startTimeout = self::START_TIMEOUT,
    ): self {
        return new self(
            ComponentKind::Process,
            $class,
            critical: $critical,
            retries: $retries,
            startTimeout: $startTimeout,
        );
    }

    /**
     * A supervised {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} fleet.
     *
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Daemon> $class
     */
    public static function daemon(string $class, bool $critical = true, int $startTimeout = self::START_TIMEOUT): self
    {
        // A daemon supervises its own workers: when it fails itself, it is not restarted.
        return new self(ComponentKind::Daemon, $class, critical: $critical, retries: 0, startTimeout: $startTimeout);
    }

    /**
     * The scheduler that runs #[Scheduled] tasks. Defaults to the built-in
     * {@see Scheduler}; pass a subclass to override discovery.
     *
     * @param class-string<Scheduler> $class
     */
    public static function scheduler(
        string $class = Scheduler::class,
        bool $critical = true,
        int $retries = self::RETRIES,
        int $startTimeout = self::START_TIMEOUT,
    ): self {
        return new self(
            ComponentKind::Scheduler,
            $class,
            critical: $critical,
            retries: $retries,
            startTimeout: $startTimeout,
        );
    }
}
