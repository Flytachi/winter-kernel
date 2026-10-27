<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\App\Attribute\EnableActuator;
use Flytachi\Winter\Kernel\App\Attribute\EnableAsync;
use Flytachi\Winter\Kernel\App\Attribute\EnableCaching;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRecord;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\WinterApplication;

/**
 * The application as a whole: what it starts (`info`), where it stands (`status`), and
 * stopping it (`stop`). `start` is served by the application entry itself — it is
 * `call run` under its full name — so reaching it here means the entry did not intercept it.
 */
final class Application extends Cmd
{
    private const int CL = 34;

    public static string $title = "the application: start | stop | status | info";

    public function handle(): void
    {
        self::printTitle("Application", self::CL);

        $app = WinterApplication::getAppClass();
        if ($app === '') {
            self::printWarning("No WinterApplication booted this command.");
            self::printInfo("Ensure your `call` launcher calls App::main(\$argv), where App extends WinterApplication.");
            self::printTitle("Application", self::CL);
            return;
        }

        match (strtolower($this->args['arguments'][1] ?? '')) {
            'info'   => $this->infoArg($app),
            'status', '' => $this->statusArg($app),
            'stop'   => $this->stopArg($app),
            'start'  => $this->startArg(),
            default  => self::printWarning("Unknown action (use start|stop|status|info)."),
        };

        self::printTitle("Application", self::CL);
    }

    // ── info ──────────────────────────────────────────────────────────────────

    /**
     * What `call application start` would start and with which configuration — read from
     * the code and the environment, no running application required.
     *
     * @param class-string<WinterApplication> $app
     */
    private function infoArg(string $app): void
    {
        self::printLabel("Application", self::CL);
        self::printKeyValue("Class", $app, 14, self::CL, 36);
        self::printKeyValue("Root", (string) Kernel::$pathRoot, 14, self::CL, 36);
        self::printKeyValue("PHP", PHP_VERSION, 14, self::CL, 36);
        self::printKeyValue(
            "Swoole",
            extension_loaded('swoole') ? (string) phpversion('swoole') : 'not loaded',
            14,
            self::CL,
            extension_loaded('swoole') ? 36 : 33
        );
        self::printLabel("Application", self::CL);

        try {
            $manifest = $app::manifest();
        } catch (\Throwable $e) {
            self::printWarning("The components cannot be resolved: " . $e->getMessage());
            return;
        }

        self::printLabel("Components", self::CL);
        if ($manifest === []) {
            self::printWarning("No components declared: `start` would refuse to run.");
            self::printInfo("Declare #[EnableHttp] / #[EnableProcess] / #[EnableDaemon] / #[EnableScheduler] / #[EnableWebSocket],");
            self::printInfo("or override components(Components \$components) on {$app}.");
            self::printLabel("Components", self::CL);
        } else {
            self::print(sprintf(
                "  %-30s %-10s %-20s %-9s %-8s %s",
                'COMPONENT', 'KIND', 'SOURCE', 'CRITICAL', 'RETRIES', 'START TIMEOUT'
            ), 90);
            foreach ($manifest as $entry) {
                /** @var Component $component */
                $component = $entry['component'];
                self::print(sprintf(
                    "  %-30s %-10s %-20s %-9s %-8s %s",
                    $this->describe($component),
                    $component->kind->name,
                    $entry['source'],
                    $component->critical ? 'yes' : 'no',
                    $this->retries($component),
                    $component->kind === ComponentKind::Command ? '-' : $component->startTimeout . 's',
                ), $component->critical ? 32 : 36);
            }
            self::printDivider();
            self::printInfo(count($manifest) . " component(s) start with the application.");
            self::printLabel("Components", self::CL);
        }

        $hasHttp = false;
        foreach ($manifest as $entry) {
            $hasHttp = $hasHttp || $entry['component']->kind === ComponentKind::Http;
        }
        if ($hasHttp) {
            $this->httpInfo($app);
        }

        self::printLabel("Configuration", self::CL);
        $reflection = new \ReflectionClass($app);
        foreach ([
            'async'    => EnableAsync::class,
            'caching'  => EnableCaching::class,
            'actuator' => EnableActuator::class,
        ] as $name => $attribute) {
            $on = $reflection->getAttributes($attribute) !== [];
            self::printKeyValue(ucfirst($name), $on ? 'enabled' : 'off', 14, self::CL, $on ? 32 : 90);
        }
        self::printKeyValue("DEBUG", $this->env('DEBUG'), 14, self::CL, 36);
        self::printKeyValue("LOG_LEVEL", $this->env('LOG_LEVEL'), 14, self::CL, 36);
        self::printLabel("Configuration", self::CL);
    }

    /**
     * The HTTP server's settings as `start` resolves them without flags — the environment
     * and the application's own configuration.
     *
     * @param class-string<WinterApplication> $app
     */
    private function httpInfo(string $app): void
    {
        self::printLabel("HTTP server", self::CL);
        try {
            $settings = $app::httpSettings();
        } catch (\Throwable $e) {
            self::printWarning("The server settings cannot be resolved: " . $e->getMessage());
            self::printLabel("HTTP server", self::CL);
            return;
        }

        $raw = $settings->toArray();
        $timeout = $settings->getRequestTimeout();
        self::printKeyValue("Address", sprintf('http://%s:%d', $settings->getHost(), $settings->getPort()), 14, self::CL, 36);
        self::printKeyValue("Workers", (string) ($raw['worker_num'] ?? 1), 14, self::CL, 36);
        self::printKeyValue("Memory", $settings->getMemoryLimit() ?? 'PHP default (' . ini_get('memory_limit') . ')', 14, self::CL, 36);
        self::printKeyValue("Timeout", $timeout > 0 ? $timeout . 's per request' : 'none', 14, self::CL, 36);
        self::printKeyValue("Profile", WinterApplication::profileSummary($settings), 14, self::CL, 36);
        self::printKeyValue("Compression", $settings->isCompressionEnabled()
            ? sprintf('br/gzip · level %d · from %s', $settings->getCompressionLevel(), self::size($settings->getCompressionMinLength()))
            : 'off', 14, self::CL, 36);
        $serverHeader = $settings->getServerHeader();
        self::printKeyValue("Server header", $serverHeader !== '' ? $serverHeader : 'not sent', 14, self::CL, 36);
        self::printLabel("HTTP server", self::CL);
    }

    // ── status ────────────────────────────────────────────────────────────────

    /**
     * Where the running application stands: its own process, then every component as the
     * supervisor last reported it.
     *
     * @param class-string<WinterApplication> $app
     */
    private function statusArg(string $app): void
    {
        self::printLabel("Application Status", self::CL);

        $record = ApplicationRuntime::live($app);
        $elsewhere = $record === null ? ApplicationRuntime::servingElsewhere() : null;

        if ($elsewhere !== null) {
            self::printBadge('Application', '◌ ANOTHER ENVIRONMENT', self::CL, 33);
            self::printKeyValue("Host", $elsewhere->host, 14, self::CL, 36);
            self::printKeyValue("PID", $elsewhere->pid . ' (there)', 14, self::CL, 36);
            self::printKeyValue("Started", $elsewhere->getStartedAt(), 14, self::CL, 36);
            self::printInfo("Runs on another host sharing this storage (a container?) — ask it there:");
            self::printInfo("docker compose exec <service> php call app status");
            self::printLabel("Application Status", self::CL);
            return;
        }
        if ($record === null) {
            self::printBadge('Application', '○ STOPPED', self::CL, 31);
            self::printInfo("The application is not running. Start it with `call application start`.");
            self::printLabel("Application Status", self::CL);
            return;
        }

        self::printBadge('Application', '● RUNNING', self::CL, 32);
        self::printDivider();
        self::printKeyValue("PID", (string) $record->pid, 14, self::CL, 36);
        self::printKeyValue("Host", $record->host, 14, self::CL, 36);
        self::printKeyValue("Started", $record->getStartedAt(), 14, self::CL, 36);
        self::printKeyValue("Uptime", $this->duration(time() - $record->startedAt), 14, self::CL, 36);
        self::printLabel("Application Status", self::CL);

        $units = ApplicationRuntime::units($app);
        if ($units === []) {
            self::printLabel("Boot", self::CL);
            self::printInfo("The supervisor has not reported yet.");
            self::printLabel("Boot", self::CL);
            return;
        }

        $boot = array_values(array_filter($units, static fn(array $u): bool => !($u['adopted'] ?? false)));
        $adopted = array_values(array_filter($units, static fn(array $u): bool => (bool) ($u['adopted'] ?? false)));

        self::printLabel("Boot — started with the application", self::CL);
        $this->printUnits($boot, true);
        self::printLabel("Boot — started with the application", self::CL);

        if ($adopted !== []) {
            self::printLabel("Adopted — started on request, run once", self::CL);
            $this->printUnits($adopted, false);
            self::printLabel("Adopted — started on request, run once", self::CL);
        }
    }

    /**
     * @param list<array<string, mixed>> $units
     * @param bool $restarts Whether they are restarted — declared ones are, adopted ones not.
     */
    private function printUnits(array $units, bool $restarts): void
    {
        $head = $restarts
            ? sprintf("  %-30s %-10s %-10s %-8s %-10s %s", 'COMPONENT', 'KIND', 'STATE', 'PID', 'FOR', 'RESTARTS')
            : sprintf("  %-30s %-10s %-10s %-8s %s", 'COMPONENT', 'KIND', 'STATE', 'PID', 'FOR');
        self::print($head, 90);
        $now = time();
        foreach ($units as $unit) {
            $row = [
                $unit['name'],
                $unit['kind'],
                strtoupper($unit['state']),
                $unit['pid'] ? (string) $unit['pid'] : '-',
                $unit['since'] ? $this->duration($now - (int) $unit['since']) : '-',
            ];
            self::print($restarts
                ? sprintf("  %-30s %-10s %-10s %-8s %-10s %s", ...[...$row, $this->restarts($unit)])
                : sprintf("  %-30s %-10s %-10s %-8s %s", ...$row), $this->stateColor($unit['state']));

            if ($unit['retryAt'] > $now) {
                self::print("      next attempt in " . $this->duration($unit['retryAt'] - $now), 90);
            }
            if ($unit['reason'] !== null && $unit['reason'] !== '') {
                self::print("      " . $unit['reason'], $unit['state'] === 'Failed' ? 31 : 90);
            }
        }
    }

    // ── stop ──────────────────────────────────────────────────────────────────

    /**
     * Asks the application to stop — SIGTERM to its process, which stops every component
     * the way a container stop does — and waits for it to go.
     *
     * --timeout=<s> how long to wait (default 60); --force SIGKILLs what is left after it.
     *
     * @param class-string<WinterApplication> $app
     */
    private function stopArg(string $app): void
    {
        $record = ApplicationRuntime::record($app);
        $alive = $record !== null ? ApplicationRuntime::isAlive($record) : false;

        if ($record === null || $alive === false) {
            self::printWarning("The application is not running.");
            return;
        }
        if ($alive === null) {
            if (ApplicationRuntime::servingElsewhere() === null) {
                self::printWarning("The application is not running (a record from {$record->host} is left, silent).");
                return;
            }
            self::printWarning("The application runs on another host ({$record->host}): stop it there.");
            return;
        }
        if (!function_exists('posix_kill')) {
            self::printWarning("Stopping needs ext-posix.");
            return;
        }

        $timeout = max(1, (int) ($this->args['options']['timeout'] ?? 60));
        $force = array_key_exists('force', $this->args['options']);
        $units = ApplicationRuntime::units($app);

        if (!posix_kill($record->pid, SIGTERM)) {
            self::printWarning("Failed to signal the application [PID:{$record->pid}].");
            return;
        }
        self::printInfo("Stop signal sent [PID:{$record->pid}] — waiting up to {$timeout}s …");

        if ($this->waitForExit($record, $timeout)) {
            self::printSuccess("Application stopped.");
            return;
        }

        if (!$force) {
            self::printWarning("Still running after {$timeout}s: a component is finishing its work.");
            self::printInfo("Wait longer with --timeout=<s>, or end it with --force.");
            return;
        }

        // The application's own process, then what it started: each component is the
        // leader of a process group of its own, which takes its workers along.
        posix_kill($record->pid, SIGKILL);
        foreach ($units as $unit) {
            if ($unit['pid']) {
                posix_kill(-(int) $unit['pid'], SIGKILL);
            }
        }
        self::printWarning("Application killed (SIGKILL) after {$timeout}s.");
    }

    private function waitForExit(ApplicationRecord $record, int $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (ApplicationRuntime::isAlive($record) === false) {
                return true;
            }
            usleep(200_000);
        }
        return ApplicationRuntime::isAlive($record) === false;
    }

    // ── start ─────────────────────────────────────────────────────────────────

    private function startArg(): void
    {
        // `application start` is owned by the application entry, like `run`:
        // WinterApplication::run() serves the app before the console is ever reached.
        self::printWarning("`call application start` is served by your WinterApplication entry, not this command.");
        self::printInfo("Ensure your `call` launcher calls App::main(\$argv), where App extends WinterApplication.");
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function describe(Component $component): string
    {
        return match ($component->kind) {
            // The HTTP server's address is the deployment's (see "HTTP server" below).
            ComponentKind::WebSocket => sprintf('%s :%d', $component->name(), $component->port),
            default => $component->name(),
        };
    }

    private function retries(Component $component): string
    {
        if (in_array($component->kind, [ComponentKind::Daemon, ComponentKind::Command], true)) {
            return '-';
        }
        return (string) $component->retries;
    }

    /**
     * @param array<string, mixed> $unit
     */
    private function restarts(array $unit): string
    {
        return $unit['restarts'] > 0 ? $unit['restarts'] . '/' . $unit['retries'] : '0';
    }

    private function stateColor(string $state): int
    {
        return match ($state) {
            'Running', 'Completed' => 32,
            'Failed' => 31,
            'Waiting', 'Starting', 'Held' => 33,
            default => 90,
        };
    }

    private function env(string $name): string
    {
        $value = env($name);
        return match (true) {
            $value === null => 'unset',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * Human-readable duration, e.g. 90061 → "1d 1h".
     */
    /** Bytes as written in `.env`: 512, 1K, 2M. */
    private static function size(int $bytes): string
    {
        foreach (['G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024] as $unit => $factor) {
            if ($bytes >= $factor && $bytes % $factor === 0) {
                return intdiv($bytes, $factor) . $unit;
            }
        }
        return (string) $bytes;
    }

    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $suffix => $size) {
            $value = intdiv($seconds, $size);
            $seconds %= $size;
            if ($value > 0) {
                $parts[] = $value . $suffix;
            }
        }
        return $parts === [] ? '0s' : implode(' ', array_slice($parts, 0, 2));
    }

    public static function help(): void
    {
        $cl = self::CL;
        self::printTitle("Application Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call application start       - run the application in the foreground (= call run)", $cl);
        self::print("call application start dev   - the same with the DevWatcher (= call run dev)", $cl);
        self::print("call application stop        - stop the running application (SIGTERM, waits)", $cl);
        self::print("call application status      - the application and every component, live", $cl);
        self::print("call application info        - what starts with the application, and its configuration", $cl);
        self::print("`app` is short for `application`: call app status", $cl);
        self::printLabel("Usage", $cl);

        self::printDivider($cl);

        self::printLabel("Options", $cl);
        self::print("stop --timeout=<s>   how long to wait for it to stop (default 60)", $cl);
        self::print("stop --force         SIGKILL the application and its components after the timeout", $cl);
        self::print("start                takes the options of `call run` (--host, --port, -w …)", $cl);
        self::printLabel("Options", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-application");

        self::printTitle("Application Help", $cl);
    }
}
