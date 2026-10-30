<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;

/**
 * The application's components declared in code — what
 * {@see \Flytachi\Winter\Kernel\WinterApplication::components()} receives. The same as the
 * #[Enable*] attributes, with what attributes cannot do: conditions, values from the
 * environment, lists from configuration — and outside programs.
 *
 * ```
 * protected static function components(Components $components): void
 * {
 *     if (env('ROLE') !== 'web') {
 *         $components->scheduler();
 *     }
 *     $components->websocket(Chat::class, port: (int) env('CHAT_WS_PORT', 8001));
 *     $components->script('scripts/legacy-sync.php', critical: false);
 *     $components->command(['node', 'tools/bridge.js'], name: 'bridge');
 * }
 * ```
 *
 * What is declared here adds to what the attributes declare; one class — or one program
 * name — declared twice stops the boot.
 *
 * @link https://winterframe.net/docs/components#components Declaring components in code
 */
final class Components
{
    /** @var list<Component> */
    private array $components = [];

    /**
     * @param string $root The project root — where relative script paths start.
     * @internal Built by the application.
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * A {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process} — as #[EnableProcess].
     *
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Process> $class
     */
    public function process(
        string $class,
        bool $critical = true,
        int $retries = Component::RETRIES,
        int $startTimeout = Component::START_TIMEOUT,
    ): self {
        $this->components[] = Component::process($class, $critical, $retries, $startTimeout);
        return $this;
    }

    /**
     * The scheduler of #[Scheduled] tasks — as #[EnableScheduler].
     *
     * @param class-string<Scheduler> $class
     */
    public function scheduler(
        string $class = Scheduler::class,
        bool $critical = true,
        int $retries = Component::RETRIES,
        int $startTimeout = Component::START_TIMEOUT,
    ): self {
        $this->components[] = Component::scheduler($class, $critical, $retries, $startTimeout);
        return $this;
    }

    /**
     * A {@see \Flytachi\Winter\Kernel\WebSocket\WebSocket} server — as #[EnableWebSocket].
     *
     * @param class-string<\Flytachi\Winter\Kernel\WebSocket\WebSocket> $class
     */
    public function websocket(
        string $class,
        int $port,
        string $host = '0.0.0.0',
        bool $critical = true,
        int $retries = Component::RETRIES,
        int $startTimeout = Component::START_TIMEOUT,
    ): self {
        $this->components[] = Component::websocket($class, $port, $host, $critical, $retries, $startTimeout);
        return $this;
    }

    /**
     * A {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} — as #[EnableDaemon]; never
     * restarted by the application.
     *
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Daemon> $class
     */
    public function daemon(string $class, bool $critical = true, int $startTimeout = Component::START_TIMEOUT): self
    {
        $this->components[] = Component::daemon($class, $critical, $startTimeout);
        return $this;
    }

    /**
     * A PHP script run as a program of its own, with this PHP — plain PHP, the framework
     * plays no part in it. See {@see command()} for how it is run and watched.
     *
     * @param string $path The script, relative to the project root or absolute.
     * @param list<string> $args Its arguments.
     * @param string|null $name How logs name it; the file name without `.php` by default.
     * @param float $grace Seconds it has to stop after SIGTERM before SIGKILL.
     * @throws ApplicationConfigException No such file.
     * @link https://winterframe.net/docs/components#script Running a PHP script beside the application
     */
    public function script(
        string $path,
        array $args = [],
        ?string $name = null,
        bool $critical = true,
        float $grace = 30.0,
    ): self {
        $file = str_starts_with($path, '/') ? $path : rtrim($this->root, '/') . '/' . $path;
        if (!is_file($file)) {
            throw new ApplicationConfigException("Script {$path} not found (looked for {$file}).");
        }
        $this->components[] = Component::command(
            [PHP_BINARY, $file, ...array_values($args)],
            $name ?? basename($file, '.php'),
            $critical,
            $grace,
        );
        return $this;
    }

    /**
     * An outside program — any executable, looked up on PATH — the application starts
     * and watches: ready as soon as it starts, never restarted (exit 0 is its work done,
     * anything else a failure), stopped with SIGTERM and then SIGKILL after `$grace`.
     * It runs in the project root with the application's environment; its output goes to
     * the application's.
     *
     * @param list<string> $command The program and its arguments.
     * @param string|null $name How logs name it; the program's name by default.
     * @param float $grace Seconds it has to stop after SIGTERM before SIGKILL.
     * @link https://winterframe.net/docs/components#command Running an outside program beside the application
     */
    public function command(array $command, ?string $name = null, bool $critical = true, float $grace = 30.0): self
    {
        $this->components[] = Component::command($command, $name, $critical, $grace);
        return $this;
    }

    /**
     * @return list<Component>
     * @internal Read by the application.
     */
    public function all(): array
    {
        return $this->components;
    }
}
