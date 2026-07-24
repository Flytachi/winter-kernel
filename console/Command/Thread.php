<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Collector\ImplementorCollector;
use Flytachi\Winter\K2\Collector\SubclassCollector;
use Flytachi\Winter\K2\Core\ClassScanner;
use Flytachi\Winter\K2\Old\Process\Core\Dispatchable;
use Flytachi\Winter\K2\Old\Process\DaemonException;
use Flytachi\Winter\K2\Old\Process\ThreadDaemon;
use Flytachi\Winter\K2\Old\Process\ThreadJob;
use Flytachi\Winter\K2\Old\Process\ThreadProcess;

class Thread extends Cmd
{
    public static string $title = "run Dispatchable thread tasks in foreground or background";

    public function handle(): void
    {
        self::printTitle("Thread", 34);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Thread", 34);
    }

    private function resolution(): void
    {
        switch ($this->args['arguments'][1] ?? '') {
            case 'list':
                $this->listArg();
                break;
            case 'daemons':
                $this->daemonsArg();
                break;
            default:
                $this->runArg($this->args['arguments'][1]);
                break;
        }
    }

    private function runArg(string $input): void
    {
        if (!extension_loaded('pcntl')) {
            self::printWarning("Extension 'pcntl' is not loaded — async signals unavailable.");
            return;
        }

        pcntl_async_signals(true);

        $class = str_replace(
            '/',
            '\\',
            implode('/', array_map(
                fn($word) => ucfirst($word),
                explode('/', str_replace('.', '/', $input))
            ))
        );
        $name = basename(str_replace('\\', '/', $class));

        if (!class_exists($class)) {
            self::printWarning("Class '$name' not found.");
            self::printInfo("Resolved: $class");
            self::printInfo("Run 'call thread list' to see available threads.");
        } elseif (!is_subclass_of($class, Dispatchable::class)) {
            self::printWarning("Class '$name' does not implement Dispatchable.");
            self::printInfo("Resolved: $class");
        } elseif (is_subclass_of($class, ThreadDaemon::class)) {
            $this->daemonAction($class);
        } else {
            $inBackground = in_array('d', $this->args['flags']);
            if ($inBackground) {
                $this->threadRunnableToBack($class);
            } else {
                $this->threadRunnable($class);
            }
        }
    }

    /**
     * @param class-string<ThreadDaemon> $class
     */
    private function daemonAction(string $class): void
    {
        match (strtolower($this->args['arguments'][2] ?? '')) {
            'start'  => $this->daemonStart($class),
            'stop'   => $this->daemonStop($class),
            'status' => $this->daemonStatus($class, in_array('v', $this->args['flags'])),
            ''       => $this->daemonToggle($class),
            default  => self::printWarning("Unknown daemon action (use start|stop|status)."),
        };
    }

    /**
     * Default daemon behavior: toggle between start and stop based on status.
     *
     * @param class-string<ThreadDaemon> $class
     */
    private function daemonToggle(string $class): void
    {
        $info = $class::status();
        if ($info) {
            self::printInfo("Already running [PID:{$info->status->pid}], stopping...");
            $this->daemonStop($class);
        } elseif (in_array('d', $this->args['flags'])) {
            $this->daemonStart($class);
        } else {
            $this->threadRunnable($class);
        }
    }

    /**
     * @param class-string<ThreadDaemon> $class
     */
    private function daemonStart(string $class): void
    {
        try {
            $pid = $class::dispatch();
            self::printSuccess("Started: $class");
            self::printKeyValue("PID", (string) $pid, 12, 34, 32);
        } catch (DaemonException $e) {
            self::printWarning($e->getMessage());
        }
    }

    /**
     * @param class-string<ThreadDaemon> $class
     */
    private function daemonStop(string $class): void
    {
        try {
            $info = $class::status();
            $class::stop();
            self::printSuccess("Stopped: $class");
            if ($info) {
                self::printKeyValue("PID", (string) $info->status->pid, 12, 34, 32);
            }
        } catch (DaemonException $e) {
            self::printWarning($e->getMessage());
        }
    }

    /**
     * @param class-string<ThreadDaemon> $class
     */
    private function daemonStatus(string $class, bool $detailed): void
    {
        $dot  = str_replace('\\', '.', $class);
        $info = $class::status($detailed);

        self::printLabel("Daemon Status", 34);

        if (!$info) {
            self::printBadge($dot, '○ STOPPED', 34, 31);
            self::printInfo("The daemon is not running.");
            self::printLabel("Daemon Status", 34);
            return;
        }

        $s = $info->status;
        self::printBadge($dot, '● RUNNING', 34, 32);
        self::printDivider();

        self::printKeyValue("PID", (string) $s->pid, 12, 34, 36);
        self::printKeyValue("Condition", $s->condition->name, 12, 34, 36);
        self::printKeyValue("Started", $s->getStartedAt(), 12, 34, 36);
        self::printKeyValue("Uptime", $this->formatDuration(time() - $s->startedAt), 12, 34, 36);
        if ($s->streamRps) {
            self::printKeyValue("Stream RPS", (string) $s->streamRps, 12, 34, 36);
        }
        self::printKeyValue("Forks", (string) $this->daemonForkQty($class), 12, 34, 36);

        foreach ($s->info as $key => $value) {
            self::printKeyValue(
                (string) $key,
                is_scalar($value) ? (string) $value : json_encode($value),
                12,
                34,
                90
            );
        }

        if ($detailed) {
            self::printDivider();
            self::printLabel("Resources", 34);
            if ($info->stats) {
                $st = $info->stats;
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
                self::printKeyValue("Command", $st->command, 12, 34, 35);
            } else {
                self::printInfo("Resource stats unavailable (process gone or 'ps' returned nothing).");
            }

            $this->daemonForks($class);
        }

        self::printLabel("Daemon Status", 34);
    }

    /**
     * @param class-string<ThreadDaemon> $class
     */
    private function daemonForkQty(string $class): int
    {
        try {
            return $class::forkQty();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Render the daemon's child forks with per-fork resource stats.
     *
     * @param class-string<ThreadDaemon> $class
     */
    private function daemonForks(string $class): void
    {
        try {
            $forks = $class::forkListInfo(true);
        } catch (\Throwable) {
            $forks = [];
        }

        self::printDivider();
        self::printLabel("Forks (" . count($forks) . ")", 34);

        if ($forks === []) {
            self::printInfo("No active forks.");
            return;
        }

        foreach ($forks as $fork) {
            $fs   = $fork->status;
            $line = sprintf(
                "#%-7d %-11s %s",
                $fs->pid,
                $fs->condition->name,
                $this->formatDuration(time() - $fs->startedAt)
            );
            if ($fork->stats) {
                $line .= sprintf(
                    "  cpu %s%%  rss %s MB",
                    $fork->stats->cpu,
                    round($fork->stats->rssMb(), 1)
                );
            }
            self::print($line, 36);
        }
    }

    /**
     * Human-readable duration, e.g. 90061 → "1d 1h".
     */
    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $units   = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

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

    private function listArg(): void
    {
        $collector = new ImplementorCollector(Dispatchable::class);
        ClassScanner::scan($collector);
        $threads = $collector->getResult();

        self::printLabel("Available Threads", 34);
        if (empty($threads)) {
            self::printWarning("No Dispatchable classes found.");
            self::printInfo("Create one that implements Dispatchable.");
        } else {
            foreach ($threads as $ref) {
                $dotName = str_replace('\\', '.', $ref->getName());
                [$type, $badgeColor] = match (true) {
                    $ref->isSubclassOf(ThreadDaemon::class)  => ['Daemon', 35],
                    $ref->isSubclassOf(ThreadJob::class)     => ['Job', 36],
                    $ref->isSubclassOf(ThreadProcess::class) => ['Process', 36],
                    default                                  => ['Dispatchable', 36],
                };
                self::printBadge($dotName, $type, 34, $badgeColor);
            }
        }
        self::printLabel("Available Threads", 34);
    }

    private function daemonsArg(): void
    {
        $collector = new SubclassCollector(ThreadDaemon::class);
        ClassScanner::scan($collector);
        $daemons = $collector->getResult();

        self::printLabel("Available Daemons", 34);
        if (empty($daemons)) {
            self::printWarning("No Daemon classes found.");
            self::printInfo("Create one that extends ThreadDaemon.");
        } else {
            foreach ($daemons as $ref) {
                $this->printDaemonRow($ref->getName());
            }
        }
        self::printLabel("Available Daemons", 34);
    }

    /**
     * Render one daemon row: name, live state, fork count and uptime.
     *
     * @param class-string<ThreadDaemon> $class
     */
    private function printDaemonRow(string $class): void
    {
        $dotName = str_replace('\\', '.', $class);
        $info    = $class::status();

        echo "\033[34m" . str_pad(" |\t $dotName ", 65, '.') . " ";
        if ($info) {
            $forks  = $this->daemonForkQty($class);
            $uptime = $this->formatDuration(time() - $info->status->startedAt);
            echo "\033[32m[● RUNNING]\033[36m [forks:{$forks}]\033[90m {$uptime}";
        } else {
            echo "\033[31m[○ STOPPED]";
        }
        echo "\033[0m\n";
    }

    /**
     * @param class-string<Dispatchable> $class
     */
    private function threadRunnable(string $class): void
    {
        self::printInfo("Starting: $class");
        ($class)::start();
        self::printSuccess("Finished: $class");
    }

    /**
     * @param class-string<Dispatchable> $class
     */
    private function threadRunnableToBack(string $class): void
    {
        $pid = ($class)::dispatch();
        self::printSuccess("Dispatched: $class");
        self::printKeyValue("PID", (string) $pid, 10, 34, 32);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Thread Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call thread <command> [args] -[flags]", $cl);
        self::print("call th     <command> [args] -[flags]    (alias)", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('list', 'list all Dispatchable classes', $cl, 36);
        self::printBadge('daemons', 'list daemons with live status', $cl, 36);
        self::printBadge('<dot.notation.ClassName>', 'run thread in foreground', $cl, 36);
        self::printBadge('<dot.notation.ClassName> -d', 'dispatch to background', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("Daemon Commands", $cl);
        self::printBadge('<Daemon>', 'toggle: stop if running, else start (foreground)', $cl, 35);
        self::printBadge('<Daemon> -d', 'toggle: start in background (-d only here)', $cl, 35);
        self::printBadge('<Daemon> start', 'start daemon in background', $cl, 35);
        self::printBadge('<Daemon> stop', 'stop running daemon', $cl, 35);
        self::printBadge('<Daemon> status', 'show daemon status', $cl, 35);
        self::printBadge('<Daemon> status -v', 'detailed: resources + forks', $cl, 35);
        self::printLabel("Daemon Commands", $cl);

        self::printLabel("Flags", $cl);
        self::printKeyValue("-d", "dispatch task as background process", 10, $cl, 36);
        self::printKeyValue("-v", "verbose daemon status (resources + forks)", 10, $cl, 36);
        self::printLabel("Flags", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call thread list");
        self::printInfo("call thread daemons");
        self::printInfo("call thread main.threads.ExampleJob");
        self::printInfo("call thread main.threads.ExampleJob -d");
        self::printInfo("call thread main.threads.ExampleDaemon");
        self::printInfo("call thread main.threads.ExampleDaemon status");
        self::printInfo("call thread main.threads.ExampleDaemon status -v");
        self::printInfo("call thread main.threads.ExampleDaemon stop");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/3.0.0/cmd-thread");

        self::printTitle("Thread Help", $cl);
    }
}
