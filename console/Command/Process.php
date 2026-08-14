<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Collector\SubclassCollector;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\Stereotype\Daemon as DaemonUnit;
use Flytachi\Winter\Kernel\Process\Stereotype\Process as ProcessUnit;

/**
 * Manages bare {@see ProcessUnit} units. Daemons are managed by `call daemon`.
 */
final class Process extends Cmd
{
    public static string $title = "manage Process units (start/stop/status)";

    public function handle(): void
    {
        self::printTitle("Process", 34);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Process", 34);
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
            self::printInfo("Run 'call process list' to see available processes.");
            return;
        }
        if (!is_subclass_of($class, ProcessUnit::class)) {
            self::printWarning("Class '$name' does not extend Process.");
            self::printInfo("Resolved: $class");
            return;
        }
        if (is_subclass_of($class, DaemonUnit::class)) {
            self::printWarning("Class '$name' is a Daemon, not a bare Process.");
            self::printInfo("Use 'call daemon " . str_replace('\\', '.', $class) . " ...' instead.");
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
     * @param class-string<ProcessUnit> $class
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
            // The detached double-fork reports the launcher PID; the real process
            // registers its own PID in the store. Poll briefly for it.
            $info = null;
            for ($i = 0; $i < 20 && $info === null; $i++) {
                usleep(50_000);
                $info = $class::status();
            }
            self::printSuccess("Dispatched (background): $class");
            self::printKeyValue("PID", (string) ($info->pid ?? $pid), 12, 34, 32);
            return;
        }

        self::printInfo("Starting: $class");
        $class::start();
        self::printSuccess("Finished: $class");
    }

    /**
     * @param class-string<ProcessUnit> $class
     */
    private function stopArg(string $class): void
    {
        $info = $class::status();
        if (!$info) {
            self::printWarning("Process is not running.");
            return;
        }
        if ($class::stop()) {
            self::printSuccess("Stop signal sent: $class");
            self::printKeyValue("PID", (string) $info->pid, 12, 34, 32);
        } else {
            self::printWarning("Failed to signal process.");
        }
    }

    /**
     * @param class-string<ProcessUnit> $class
     */
    private function statusArg(string $class, bool $detailed): void
    {
        $dot = str_replace('\\', '.', $class);
        $info = $class::status($detailed);

        self::printLabel("Process Status", 34);

        if (!$info) {
            self::printBadge($dot, '○ STOPPED', 34, 31);
            self::printInfo("The process is not running.");
            self::printLabel("Process Status", 34);
            return;
        }

        self::printBadge($dot, 'Process ● ' . $info->state->name, 34, 32);
        self::printDivider();
        self::printKeyValue("PID", (string) $info->pid, 12, 34, 36);
        self::printKeyValue("State", $info->state->name, 12, 34, 36);
        self::printKeyValue(
            "Activity",
            $info->activity->name,
            12,
            34,
            $info->activity === Activity::BUSY ? 33 : 90
        );
        self::printKeyValue("Started", $info->getStartedAt(), 12, 34, 36);
        self::printKeyValue("Uptime", $this->formatDuration(time() - $info->startedAt), 12, 34, 36);
        if ($info->concurrency > 0) {
            self::printKeyValue("Concurrency", (string) $info->concurrency, 12, 34, 36);
        }

        if ($detailed && $info->usage) {
            $u = $info->usage;
            self::printDivider();
            self::printLabel("Resources", 34);
            self::printKeyValue("User", $u->user, 12, 34, 35);
            self::printKeyValue("PPID", (string) $u->ppid, 12, 34, 35);
            self::printKeyValue("CPU", $u->cpu . ' %', 12, 34, 35);
            self::printKeyValue(
                "Memory",
                $u->memory . ' % (' . round($u->rssMb(), 1) . ' MB)',
                12,
                34,
                35
            );
            self::printKeyValue("Elapsed", $u->elapsed, 12, 34, 35);
        }

        self::printLabel("Process Status", 34);
    }

    private function listArg(): void
    {
        $collector = new SubclassCollector(ProcessUnit::class);
        ClassScanner::scan($collector);
        $processes = array_filter(
            $collector->getResult(),
            static fn($ref) => !$ref->isSubclassOf(DaemonUnit::class)
        );

        self::printLabel("Available Processes", 34);
        if (empty($processes)) {
            self::printWarning("No Process classes found.");
            self::printInfo("Create one that extends Process. (Daemons: 'call daemon list'.)");
            self::printLabel("Available Processes", 34);
            return;
        }

        $running = 0;
        foreach ($processes as $ref) {
            if ($this->printRow($ref->getName())) {
                $running++;
            }
        }

        self::printDivider();
        self::printInfo(count($processes) . " defined, {$running} running.");
        self::printLabel("Available Processes", 34);
    }

    /**
     * Renders one process as a padded, colour-coded row. Returns whether it runs.
     *
     * @param class-string<ProcessUnit> $class
     */
    private function printRow(string $class): bool
    {
        $dot = str_replace('\\', '.', $class);
        $info = $class::status();

        echo "\033[34m" . str_pad(" |\t [P] {$dot} ", 72, '.') . " ";
        if (!$info) {
            echo "\033[31m[○ STOPPED]\033[0m\n";
            return false;
        }

        $uptime = $this->formatDuration(time() - $info->startedAt);
        echo "\033[32m[● {$info->state->name}]"
            . $this->activityTag($info->activity)
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
     * Dot/dashed notation → FQCN, e.g. `main.process.Backup` → `Main\Process\Backup`.
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
        $cl = 34;
        self::printTitle("Process Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call process <command> [action] -[flags]", $cl);
        self::print("call proc    <command> [action] -[flags]    (alias)", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('list', 'list all bare processes with live state', $cl, 36);
        self::printBadge('<dot.notation.Class>', 'start in foreground (default)', $cl, 36);
        self::printBadge('<dot.notation.Class> start -d', 'start detached in background', $cl, 36);
        self::printBadge('<dot.notation.Class> stop', 'send graceful stop signal (SIGTERM)', $cl, 36);
        self::printBadge('<dot.notation.Class> status -v', 'detailed: resource usage', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);
        self::printInfo("Daemons (supervised fleets) are managed by 'call daemon'.");
        self::printInfo("Row tags: [P] process | ● running, ○ stopped | [BUSY]/[idle]");

        self::printDivider($cl);

        self::printInfo("Docs: https://winterframe.net/docs/cmd-process");


        self::printTitle("Process Help", $cl);
    }
}
