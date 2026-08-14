<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Collector\SubclassCollector;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon as DaemonUnit;
use Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus;
use Flytachi\Winter\Kernel\Process\Daemon\SlotState;
use Flytachi\Winter\Kernel\Process\Daemon\WorkerStatus;

/**
 * Manages supervised {@see DaemonUnit} fleets (start/stop/status), including the
 * per-worker view. Bare processes are managed by `call process`.
 */
final class Daemon extends Cmd
{
    public static string $title = "manage Daemon fleets (start/stop/status)";

    public function handle(): void
    {
        self::printTitle("Daemon", 35);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Daemon", 35);
    }

    private function resolution(): void
    {
        $input = $this->args['arguments'][1];
        if ($input === 'list') {
            $this->listArg();
            return;
        }

        $class = $this->resolveClass($input);
        $name = basename(str_replace('\\', '/', $class));

        if (!class_exists($class)) {
            self::printWarning("Class '$name' not found.");
            self::printInfo("Resolved: $class");
            self::printInfo("Run 'call daemon list' to see available daemons.");
            return;
        }
        if (!is_subclass_of($class, DaemonUnit::class)) {
            self::printWarning("Class '$name' is not a Daemon.");
            self::printInfo("Resolved: $class");
            self::printInfo("Bare processes: 'call process " . str_replace('\\', '.', $class) . " ...'.");
            return;
        }

        match (strtolower($this->args['arguments'][2] ?? '')) {
            'start'  => $this->startArg($class),
            'stop'   => $this->stopArg($class),
            'status' => $this->statusArg($class, in_array('v', $this->args['flags'])),
            ''       => $this->startArg($class),
            default  => self::printWarning("Unknown action (use start|stop|status)."),
        };
    }

    /**
     * @param class-string<DaemonUnit> $class
     */
    private function startArg(string $class): void
    {
        $info = $class::status();
        if ($info) {
            self::printWarning("Already running [PID:{$info->pid}] ({$info->getStartedAt()}).");
            return;
        }

        if (in_array('d', $this->args['flags'])) {
            $pid = $class::dispatch();
            $info = null;
            for ($i = 0; $i < 20 && $info === null; $i++) {
                usleep(50_000);
                $info = $class::status();
            }
            self::printSuccess("Dispatched (background): $class");
            self::printKeyValue("PID", (string) ($info->pid ?? $pid), 12, 35, 32);
            return;
        }

        self::printInfo("Supervising: $class");
        $class::start();
        self::printSuccess("Finished: $class");
    }

    /**
     * @param class-string<DaemonUnit> $class
     */
    private function stopArg(string $class): void
    {
        $info = $class::status();
        if (!$info) {
            self::printWarning("Daemon is not running.");
            return;
        }
        if ($class::stop()) {
            self::printSuccess("Stop signal sent: $class");
            self::printKeyValue("PID", (string) $info->pid, 12, 35, 32);
        } else {
            self::printWarning("Failed to signal daemon.");
        }
    }

    /**
     * @param class-string<DaemonUnit> $class
     */
    private function statusArg(string $class, bool $detailed): void
    {
        $dot = str_replace('\\', '.', $class);
        $info = $class::status($detailed);

        self::printLabel("Daemon Status", 35);

        if (!$info) {
            self::printBadge($dot, '○ STOPPED', 35, 31);
            self::printInfo("The daemon is not running.");
            self::printLabel("Daemon Status", 35);
            return;
        }

        self::printBadge($dot, 'Daemon ● ' . $info->state->name, 35, 32);
        self::printDivider();
        self::printKeyValue("PID", (string) $info->pid, 12, 35, 36);
        self::printKeyValue("State", $info->state->name, 12, 35, 36);
        self::printKeyValue(
            "Activity",
            $info->activity->name,
            12,
            35,
            $info->activity === Activity::BUSY ? 33 : 90
        );
        self::printKeyValue("Started", $info->getStartedAt(), 12, 35, 36);
        self::printKeyValue("Uptime", $this->formatDuration(time() - $info->startedAt), 12, 35, 36);
        if ($info->concurrency > 0) {
            self::printKeyValue("Concurrency", (string) $info->concurrency, 12, 35, 36);
        }
        if ($info instanceof DaemonStatus) {
            self::printKeyValue("Workers", (string) count($info->workers), 12, 35, 36);
            self::printKeyValue("Restarts", (string) $info->restarts, 12, 35, 36);
        }

        if ($info instanceof DaemonStatus && $info->workers !== []) {
            $this->printWorkers($info->workers);
        }

        self::printLabel("Daemon Status", 35);
    }

    /**
     * @param array<WorkerStatus> $workers
     */
    private function printWorkers(array $workers): void
    {
        self::printDivider();
        self::printLabel("Workers (" . count($workers) . ")", 35);
        self::print(
            sprintf("  %-6s %-8s %-11s %-6s %-9s %s", 'SLOT', 'PID', 'STATE', 'ACT', 'UPTIME', 'RESTARTS'),
            90
        );
        foreach ($workers as $w) {
            $pid = $w->pid > 0 ? (string) $w->pid : '—';
            $uptime = $w->startedAt > 0 ? $this->formatDuration(time() - $w->startedAt) : '—';
            $line = sprintf(
                "  #%-5d %-8s %-11s %-6s %-9s %d",
                $w->slot,
                $pid,
                $w->state->value,
                $w->activity->value,
                $uptime,
                $w->restarts,
            );
            self::print($line, $this->stateColor($w->state));
        }
    }

    private function stateColor(SlotState $state): int
    {
        return match ($state) {
            SlotState::RUNNING    => 32, // green
            SlotState::STARTING   => 36, // cyan
            SlotState::RETIRING   => 33, // yellow
            SlotState::KILLING    => 31, // red
            SlotState::RESTARTING => 35, // magenta
            SlotState::RETIRED    => 90, // dim
            SlotState::EMPTY      => 90, // dim
        };
    }

    private function listArg(): void
    {
        $collector = new SubclassCollector(DaemonUnit::class);
        ClassScanner::scan($collector);
        $daemons = $collector->getResult();

        self::printLabel("Available Daemons", 35);
        if (empty($daemons)) {
            self::printWarning("No Daemon classes found.");
            self::printInfo("Create one that extends Daemon.");
            self::printLabel("Available Daemons", 35);
            return;
        }

        $running = 0;
        foreach ($daemons as $ref) {
            if ($this->printRow($ref->getName())) {
                $running++;
            }
        }

        self::printDivider();
        self::printInfo(count($daemons) . " defined, {$running} running.");
        self::printLabel("Available Daemons", 35);
    }

    /**
     * Renders one daemon as a padded, colour-coded row. Returns whether it runs.
     *
     * @param class-string<DaemonUnit> $class
     */
    private function printRow(string $class): bool
    {
        $dot = str_replace('\\', '.', $class);
        $info = $class::status();

        echo "\033[35m" . str_pad(" |\t [D] {$dot} ", 72, '.') . " ";
        if (!$info) {
            echo "\033[31m[○ STOPPED]\033[0m\n";
            return false;
        }

        $uptime = $this->formatDuration(time() - $info->startedAt);
        $workers = $info instanceof DaemonStatus ? "\033[36m [w:" . count($info->workers) . "]" : '';
        echo "\033[32m[● {$info->state->name}]"
            . $this->activityTag($info->activity)
            . $workers
            . "\033[90m {$uptime}\033[0m\n";

        return true;
    }

    /**
     * Colour-coded activity label: BUSY is highlighted, IDLE is dim.
     */
    private function activityTag(Activity $activity): string
    {
        return $activity === Activity::BUSY
            ? "\033[33m [BUSY]"
            : "\033[90m [idle]";
    }

    /**
     * Dot/dashed notation → FQCN, e.g. `main.daemon.Emails` → `Main\Daemon\Emails`.
     */
    private function resolveClass(string $input): string
    {
        return str_replace(
            '/',
            '\\',
            implode('/', array_map(
                fn($word) => ucfirst($word),
                explode('/', str_replace('.', '/', $input))
            ))
        );
    }

    /**
     * Human-readable duration, e.g. 90061 → "1d 1h".
     */
    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $units = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

        $parts = [];
        foreach ($units as $suffix => $size) {
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
        $cl = 35;
        self::printTitle("Daemon Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call daemon <command> [action] -[flags]", $cl);
        self::print("call dmn    <command> [action] -[flags]    (alias)", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('list', 'list all daemons with live state + worker count', $cl, 36);
        self::printBadge('<dot.notation.Class>', 'supervise in foreground (default)', $cl, 36);
        self::printBadge('<dot.notation.Class> start -d', 'supervise detached in background', $cl, 36);
        self::printBadge('<dot.notation.Class> stop', 'graceful stop (drains the whole fleet)', $cl, 36);
        self::printBadge('<dot.notation.Class> status', 'status + per-worker fleet table', $cl, 36);
        self::printBadge('<dot.notation.Class> status -v', 'also master resource usage', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);
        self::printInfo("Worker states: running / starting / retiring / killing / restarting");
        self::printInfo("Bare processes are managed by 'call process'.");

        self::printDivider($cl);

        self::printInfo("Docs: https://winterframe.net/docs/cmd-daemon");

        self::printTitle("Daemon Help", $cl);
    }
}
