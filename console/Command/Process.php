<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Collector\SubclassCollector;
use Flytachi\Winter\K2\Core\ClassScanner;
use Flytachi\Winter\K2\Dev\Process\Daemon as DaemonUnit;
use Flytachi\Winter\K2\Dev\Process\Process as ProcessUnit;
use Flytachi\Winter\K2\Process\Entity\TStats;

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

        self::printBadge($dot, '● ' . $info->state->name, 34, 32);
        self::printDivider();
        self::printKeyValue("PID", (string) $info->pid, 12, 34, 36);
        self::printKeyValue("State", $info->state->name, 12, 34, 36);
        self::printKeyValue("Started", $info->getStartedAt(), 12, 34, 36);
        self::printKeyValue("Uptime", $this->formatDuration(time() - $info->startedAt), 12, 34, 36);
        if ($info->concurrency > 0) {
            self::printKeyValue("Concurrency", (string) $info->concurrency, 12, 34, 36);
        }
        if (is_subclass_of($class, DaemonUnit::class)) {
            self::printKeyValue("Workers", (string) count($info->workers), 12, 34, 36);
            self::printKeyValue("Restarts", (string) $info->restarts, 12, 34, 36);
        }

        if ($detailed && $info->stats) {
            $st = $info->stats;
            self::printDivider();
            self::printLabel("Resources", 34);
            self::printKeyValue("User", $st->user, 12, 34, 35);
            self::printKeyValue("PPID", (string) $st->ppid, 12, 34, 35);
            self::printKeyValue("CPU", $st->cpu . ' %', 12, 34, 35);
            self::printKeyValue(
                "Memory",
                $st->mem . ' % (' . round($st->rssMb(), 1) . ' MB)',
                12,
                34,
                35
            );
            self::printKeyValue("Elapsed", $st->etime, 12, 34, 35);
        }

        if ($detailed && $info->workers !== []) {
            self::printDivider();
            self::printLabel("Workers (" . count($info->workers) . ")", 34);
            foreach ($info->workers as $wpid) {
                $ws = TStats::ofPid($wpid);
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
        } else {
            foreach ($processes as $ref) {
                $class = $ref->getName();
                $dot = str_replace('\\', '.', $class);
                $type = $ref->isSubclassOf(DaemonUnit::class) ? 'Daemon' : 'Process';
                $info = $class::status();
                $badge = $info ? '● ' . $info->state->name : '○ STOPPED';
                self::printBadge($dot, "[$type] $badge", 34, $info ? 32 : 31);
            }
        }
        self::printLabel("Available Processes", 34);
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
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('list', 'list all Process classes with live state', $cl, 36);
        self::printBadge('<dot.notation.Class>', 'start in foreground (default)', $cl, 36);
        self::printBadge('<dot.notation.Class> start', 'start in foreground', $cl, 36);
        self::printBadge('<dot.notation.Class> start -d', 'start detached in background', $cl, 36);
        self::printBadge('<dot.notation.Class> stop', 'send graceful stop signal', $cl, 36);
        self::printBadge('<dot.notation.Class> status', 'show status', $cl, 36);
        self::printBadge('<dot.notation.Class> status -v', 'detailed: resources', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("Flags", $cl);
        self::printKeyValue("-d", "start detached in background", 10, $cl, 36);
        self::printKeyValue("-v", "verbose status (resource stats)", 10, $cl, 36);
        self::printLabel("Flags", $cl);

        self::printTitle("Process Help", $cl);
    }
}
