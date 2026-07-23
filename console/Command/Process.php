<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Collector\SubclassCollector;
use Flytachi\Winter\K2\Core\ClassScanner;
use Flytachi\Winter\K2\Dev\Process\Activity;
use Flytachi\Winter\K2\Dev\Process\Daemon as DaemonUnit;
use Flytachi\Winter\K2\Dev\Process\DaemonStatus;
use Flytachi\Winter\K2\Dev\Process\Process as ProcessUnit;
use Flytachi\Winter\K2\Dev\Process\ResourceUsage;

class Process extends Cmd
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

        $isDaemon = is_subclass_of($class, DaemonUnit::class);
        self::printBadge($dot, ($isDaemon ? 'Daemon ' : 'Process ') . '● ' . $info->state->name, 34, 32);
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
        if ($info instanceof DaemonStatus) {
            self::printKeyValue("Workers", (string) count($info->workers), 12, 34, 36);
            self::printKeyValue("Restarts", (string) $info->restarts, 12, 34, 36);
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

        if ($detailed && $info instanceof DaemonStatus && $info->workers !== []) {
            self::printDivider();
            self::printLabel("Workers (" . count($info->workers) . ")", 34);
            foreach ($info->workers as $wpid) {
                $ws = ResourceUsage::ofPid($wpid);
                $line = $ws
                    ? sprintf("#%-7d cpu %s%%  rss %s MB", $wpid, $ws->cpu, round($ws->rssMb(), 1))
                    : sprintf("#%-7d (gone)", $wpid);
                self::print($line, 36);
            }
        }

        self::printLabel("Process Status", 34);
    }

    private function listArg(): void
    {
        $collector = new SubclassCollector(ProcessUnit::class);
        ClassScanner::scan($collector);
        $processes = $collector->getResult();

        self::printLabel("Available Processes", 34);
        if (empty($processes)) {
            self::printWarning("No Process classes found.");
            self::printInfo("Create one that extends Process.");
            self::printLabel("Available Processes", 34);
            return;
        }

        $running = 0;
        foreach ($processes as $ref) {
            $class = $ref->getName();
            $isDaemon = $ref->isSubclassOf(DaemonUnit::class);
            if ($this->printRow($class, $isDaemon)) {
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
    private function printRow(string $class, bool $isDaemon): bool
    {
        $dot = str_replace('\\', '.', $class);
        $tag = $isDaemon ? 'D' : 'P';
        $info = $class::status();

        echo "\033[34m" . str_pad(" |\t [{$tag}] {$dot} ", 72, '.') . " ";
        if (!$info) {
            echo "\033[31m[○ STOPPED]\033[0m\n";
            return false;
        }

        $uptime = $this->formatDuration(time() - $info->startedAt);
        echo "\033[32m[● {$info->state->name}]"
            . $this->activityTag($info->activity)
            . ($info instanceof DaemonStatus ? "\033[36m [w:" . count($info->workers) . "]" : '')
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
        self::printBadge('list', 'list all processes with live state', $cl, 36);
        self::printBadge('<dot.notation.Class>', 'start in foreground (default)', $cl, 36);
        self::printBadge('<dot.notation.Class> start', 'start in foreground', $cl, 36);
        self::printBadge('<dot.notation.Class> start -d', 'start detached in background', $cl, 36);
        self::printBadge('<dot.notation.Class> stop', 'send graceful stop signal (SIGTERM)', $cl, 36);
        self::printBadge('<dot.notation.Class> status', 'show status', $cl, 36);
        self::printBadge('<dot.notation.Class> status -v', 'detailed: resources + workers', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("Flags", $cl);
        self::printKeyValue("-d", "start detached in background", 10, $cl, 36);
        self::printKeyValue("-v", "verbose status (resource usage)", 10, $cl, 36);
        self::printLabel("Flags", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call process list");
        self::printInfo("call process main.process.Consumer -d");
        self::printInfo("call process main.process.Consumer status -v");
        self::printInfo("call proc main.process.Consumer stop");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Row tags: [P] process, [D] daemon | ● running, ○ stopped | [BUSY]/[idle]");

        self::printTitle("Process Help", $cl);
    }
}
