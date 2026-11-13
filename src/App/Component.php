<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;
use Flytachi\Winter\Kernel\WebSocket\WebSocket;

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
    /** The scheme of a readiness check that connects to a port: `tcp://host:port`. */
    private const string TCP = 'tcp://';

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
     * @param bool $adopted Not declared: started while the application runs, and taken in.
     * @param array<string, string> $args What it was started with ({@see Process::startWith()}).
     * @param Priority $priority When it starts and stops relative to the others.
     * @param list<string>|string|null $ready How an outside program is known to be ready: a
     *     command that succeeds once it is, or `tcp://host:port` that accepts a connection
     *     once it is. Null: ready as soon as it runs (Command).
     * @param int $stopSignal The signal that asks it to stop (Command).
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
        public float $grace = 30.0,
        public bool $adopted = false,
        public array $args = [],
        public Priority $priority = Priority::Normal,
        public array|string|null $ready = null,
        public int $stopSignal = SIGTERM,
    ) {
        if ($retries < 0 || $startTimeout < 0) {
            throw new \InvalidArgumentException('retries and startTimeout cannot be negative.');
        }
        if ($grace < 0) {
            throw new \InvalidArgumentException('grace cannot be negative.');
        }
        if ($kind === ComponentKind::Http || $kind === ComponentKind::WebSocket) {
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException("port must be 1–65535, {$port} given.");
            }
            if (trim($host) === '') {
                throw new \InvalidArgumentException('host cannot be empty.');
            }
        }
        if ($ready !== null) {
            self::assertReadyCheck($ready);
        }
        if ($stopSignal < 1 || $stopSignal > 64) {
            throw new \InvalidArgumentException("stopSignal must be a signal number, {$stopSignal} given.");
        }
    }

    /** @param list<string>|string $ready */
    private static function assertReadyCheck(array|string $ready): void
    {
        if (is_string($ready)) {
            $at = strrpos($ready, ':');
            $port = $at === false ? '' : substr($ready, $at + 1);
            $host = $at === false ? '' : substr($ready, strlen(self::TCP), $at - strlen(self::TCP));
            $valid = str_starts_with($ready, self::TCP) && $host !== '' && ctype_digit($port)
                && (int) $port >= 1 && (int) $port <= 65535;
            if (!$valid) {
                throw new \InvalidArgumentException(
                    "ready must be a command (a list) or tcp://host:port, \"{$ready}\" given."
                );
            }
            return;
        }
        $strings = array_filter($ready, static fn($part): bool => is_string($part)) === $ready;
        if ($ready === [] || !$strings || !array_is_list($ready) || $ready[0] === '') {
            throw new \InvalidArgumentException(
                'a ready command is a non-empty list of strings: the program, then its arguments.'
            );
        }
    }

    /**
     * The same component bound elsewhere — what `--<name>.port` / `--<name>.host` asked
     * for at launch.
     *
     * @throws \InvalidArgumentException The address is out of range.
     */
    public function at(string $host, int $port): self
    {
        return new self(
            $this->kind,
            $this->class,
            BindAddress::bare($host),
            $port,
            $this->critical,
            $this->retries,
            $this->startTimeout,
            $this->command,
            $this->label,
            $this->grace,
            $this->adopted,
            $this->args,
            $this->priority,
            $this->ready,
            $this->stopSignal,
        );
    }

    /** Whether it binds an address of its own: the HTTP server or a WebSocket. */
    public function listens(): bool
    {
        return $this->kind === ComponentKind::Http || $this->kind === ComponentKind::WebSocket;
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
     * tool written in another language, a database. Exit 0 means its work is done, any
     * other exit that it failed. Stopped with `$stopSignal` (SIGTERM), then SIGKILL after
     * `$grace` seconds. Its output goes to the application's.
     *
     * It is ready as soon as it starts, unless `$ready` says how to tell: a command that
     * succeeds once it is (`['pg_isready', '-q']`), or `tcp://host:port` that accepts a
     * connection once it is — asked every half second, for up to `$startTimeout` seconds.
     * It is not restarted unless `$retries` says so.
     *
     * The program is looked up on PATH, and runs in the project's root with the
     * application's environment.
     *
     * @param list<string> $command The program and its arguments: `['node', 'bridge.js']`.
     * @param string|null $name How logs name it; the program's name by default.
     * @param list<string>|string|null $ready How it is known to be ready; null: once it runs.
     * @param int $stopSignal The signal that asks it to stop: SIGINT for PostgreSQL's fast shutdown.
     * @param int $startTimeout Seconds `$ready` has to succeed; 0 waits for good.
     * @link https://winterframe.net/docs/components#command Outside programs
     */
    public static function command(
        array $command,
        ?string $name = null,
        bool $critical = true,
        float $grace = 30.0,
        Priority $priority = Priority::Normal,
        array|string|null $ready = null,
        int $stopSignal = SIGTERM,
        int $retries = 0,
        int $startTimeout = self::START_TIMEOUT,
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
            retries: $retries,
            // Without a check it is ready the moment it runs: there is nothing to time.
            startTimeout: $ready === null ? 0 : $startTimeout,
            command: $command,
            label: $name ?? basename($command[0]),
            grace: $grace,
            priority: $priority,
            ready: $ready,
            stopSignal: $stopSignal,
        );
    }

    /**
     * A component the application was not declared with, started while it runs — from
     * the console or with `dispatch()` — and taken in: it runs once. Never restarted,
     * never critical: whatever becomes of it, the application runs on.
     *
     * @param class-string<Process> $class
     * @param array<string, string> $args What it was started with; a WebSocket's `port`
     *     and `host` among them.
     * @throws \InvalidArgumentException Not a process class, or a WebSocket without a port.
     */
    public static function adopted(string $class, array $args = []): self
    {
        if (!class_exists($class) || !is_subclass_of($class, Process::class)) {
            throw new \InvalidArgumentException("{$class} is not a Process class.");
        }
        $kind = match (true) {
            is_subclass_of($class, WebSocket::class) => ComponentKind::WebSocket,
            is_subclass_of($class, Daemon::class) => ComponentKind::Daemon,
            $class === Scheduler::class || is_subclass_of($class, Scheduler::class) => ComponentKind::Scheduler,
            default => ComponentKind::Process,
        };
        $port = 8000;
        $host = '0.0.0.0';
        if ($kind === ComponentKind::WebSocket) {
            if (!ctype_digit($args['port'] ?? '')) {
                throw new \InvalidArgumentException("{$class} is a WebSocket: it needs a port.");
            }
            $port = (int) $args['port'];
            $host = ($args['host'] ?? '') !== '' ? BindAddress::bare($args['host']) : $host;
        }
        return new self(
            $kind,
            $class,
            $host,
            $port,
            critical: false,
            retries: 0,
            adopted: true,
            args: $args,
        );
    }

    /**
     * The main HTTP server — controllers under the app root are scanned automatically.
     * Its address can be changed at launch with `--http.port` / `--http.host`, and by
     * a {@see \Flytachi\Winter\Kernel\App\Config\WebConfigurer} after that.
     */
    public static function http(
        string $host = '0.0.0.0',
        int $port = 8000,
        bool $critical = true,
        int $retries = self::RETRIES,
        int $startTimeout = self::START_TIMEOUT,
        Priority $priority = Priority::Normal,
    ): self {
        return new self(
            ComponentKind::Http,
            host: BindAddress::bare($host),
            port: $port,
            critical: $critical,
            retries: $retries,
            startTimeout: $startTimeout,
            label: 'Http',
            priority: $priority,
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
        Priority $priority = Priority::Normal,
    ): self {
        return new self(
            ComponentKind::WebSocket,
            $class,
            BindAddress::bare($host),
            $port,
            $critical,
            $retries,
            $startTimeout,
            priority: $priority,
        );
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
        Priority $priority = Priority::Normal,
    ): self {
        return new self(
            ComponentKind::Process,
            $class,
            critical: $critical,
            retries: $retries,
            startTimeout: $startTimeout,
            priority: $priority,
        );
    }

    /**
     * A supervised {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} fleet.
     *
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Daemon> $class
     */
    public static function daemon(
        string $class,
        bool $critical = true,
        int $startTimeout = self::START_TIMEOUT,
        Priority $priority = Priority::Normal,
    ): self {
        // A daemon supervises its own workers: when it fails itself, it is not restarted.
        return new self(
            ComponentKind::Daemon,
            $class,
            critical: $critical,
            retries: 0,
            startTimeout: $startTimeout,
            priority: $priority,
        );
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
        Priority $priority = Priority::Normal,
    ): self {
        return new self(
            ComponentKind::Scheduler,
            $class,
            critical: $critical,
            retries: $retries,
            startTimeout: $startTimeout,
            priority: $priority,
        );
    }
}
