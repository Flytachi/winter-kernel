<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Core\ClassScanner;
use Flytachi\Winter\K2\Process\Activity;
use Flytachi\Winter\K2\Schedule\Scheduler;
use Flytachi\Winter\K2\Schedule\ScheduledCollector;
use Flytachi\Winter\K2\Schedule\ScheduledTask;

/**
 * Runs and inspects the {@see Scheduler} — the single process that fires every
 * `#[Scheduled]` method. Unlike `call process` / `call daemon` there is no class
 * to name: the scheduler is one fixed runtime, and the schedule is the set of
 * annotated methods it discovers.
 */
class Schedule extends Cmd
{
    private const int CL = 36;

    public static string $title = "run the scheduler and list #[Scheduled] tasks";

    public function handle(): void
    {
        self::printTitle("Schedule", self::CL);

        match (strtolower($this->args['arguments'][1] ?? '')) {
            'list'   => $this->listArg(),
            'start'  => $this->startArg(),
            'stop'   => $this->stopArg(),
            'status' => $this->statusArg(in_array('v', $this->args['flags'])),
            ''       => $this->listArg(),
            default  => self::printWarning("Unknown action (use list|start|stop|status)."),
        };

        self::printTitle("Schedule", self::CL);
    }

    /**
     * Lists the discovered tasks and their cadence — a static scan, no running
     * scheduler required.
     */
    private function listArg(): void
    {
        $tasks = $this->discover();

        self::printLabel("Scheduled Tasks", self::CL);
        if ($tasks === []) {
            self::printWarning("No #[Scheduled] methods found.");
            self::printInfo("Annotate a public, no-argument method with #[Scheduled].");
            self::printLabel("Scheduled Tasks", self::CL);
            return;
        }

        self::print(sprintf("  %-46s %s", 'TASK', 'TRIGGER'), 90);
        foreach ($tasks as $task) {
            self::print(sprintf("  %-46s %s", $task->id(), $task->trigger->describe()), 32);
        }

        self::printDivider();
        self::printInfo(count($tasks) . " task(s) defined.");
        self::printLabel("Scheduled Tasks", self::CL);
    }

    private function startArg(): void
    {
        $info = Scheduler::status();
        if ($info) {
            self::printWarning("Scheduler already running [PID:{$info->pid}] ({$info->getStartedAt()}).");
            return;
        }

        if (in_array('d', $this->args['flags'])) {
            $pid = Scheduler::dispatch();
            $info = null;
            for ($i = 0; $i < 20 && $info === null; $i++) {
                usleep(50_000);
                $info = Scheduler::status();
            }
            self::printSuccess("Scheduler dispatched (background).");
            self::printKeyValue("PID", (string) ($info->pid ?? $pid), 12, self::CL, 32);
            return;
        }

        self::printInfo("Starting scheduler …");
        Scheduler::start();
        self::printSuccess("Scheduler finished.");
    }

    private function stopArg(): void
    {
        $info = Scheduler::status();
        if (!$info) {
            self::printWarning("Scheduler is not running.");
            return;
        }
        if (Scheduler::stop()) {
            self::printSuccess("Stop signal sent.");
            self::printKeyValue("PID", (string) $info->pid, 12, self::CL, 32);
        } else {
            self::printWarning("Failed to signal scheduler.");
        }
    }

    private function statusArg(bool $detailed): void
    {
        $info = Scheduler::status($detailed);

        self::printLabel("Scheduler Status", self::CL);
        if (!$info) {
            self::printBadge('Scheduler', '○ STOPPED', self::CL, 31);
            self::printInfo("The scheduler is not running.");
            self::printLabel("Scheduler Status", self::CL);
            return;
        }

        self::printBadge('Scheduler', 'Scheduler ● ' . $info->state->name, self::CL, 32);
        self::printDivider();
        self::printKeyValue("PID", (string) $info->pid, 12, self::CL, 36);
        self::printKeyValue("State", $info->state->name, 12, self::CL, 36);
        self::printKeyValue(
            "Activity",
            $info->activity->name,
            12,
            self::CL,
            $info->activity === Activity::BUSY ? 33 : 90
        );
        self::printKeyValue("Started", $info->getStartedAt(), 12, self::CL, 36);
        self::printKeyValue("Uptime", $this->formatDuration(time() - $info->startedAt), 12, self::CL, 36);
        self::printKeyValue("Tasks", (string) count($this->discover()), 12, self::CL, 36);

        self::printLabel("Scheduler Status", self::CL);
    }

    /**
     * @return ScheduledTask[]
     */
    private function discover(): array
    {
        $collector = new ScheduledCollector();
        ClassScanner::scan($collector);
        return $collector->getResult();
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
        $cl = self::CL;
        self::printTitle("Schedule Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call schedule <action> -[flags]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('list', 'list all #[Scheduled] tasks and their cadence (default)', $cl, 36);
        self::printBadge('start', 'run the scheduler in the foreground', $cl, 36);
        self::printBadge('start -d', 'run the scheduler detached in the background', $cl, 36);
        self::printBadge('stop', 'send a graceful stop signal (SIGTERM)', $cl, 36);
        self::printBadge('status', 'scheduler run state + task count', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);
        self::printInfo("The scheduler fires one process per host (singleton lock).");

        self::printTitle("Schedule Help", $cl);
    }
}
