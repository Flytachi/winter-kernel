<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Console\Inc\KnowsTheApplication;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;
use Flytachi\Winter\Kernel\Schedule\ScheduledCollector;
use Flytachi\Winter\Kernel\Schedule\ScheduledTask;

/**
 * Runs and inspects the {@see Scheduler} — the single process that fires every
 * `#[Scheduled]` method. Unlike `call process` / `call daemon` there is no class
 * to name: the scheduler is one fixed runtime, and the schedule is the set of
 * annotated methods it discovers.
 */
final class Schedule extends Cmd
{
    use KnowsTheApplication;

    private const int CL = 36;

    public static string $title = "run the scheduler and list #[Scheduled] tasks";

    public function handle(): void
    {
        self::printTitle("Schedule", self::CL);

        match (strtolower($this->args['arguments'][1] ?? '')) {
            'list'   => $this->listArg(),
            'start'  => $this->startArg(),
            'stop'   => $this->stopArg(),
            'restart' => $this->restartThroughApplication($this->scheduler(), 'schedule', self::CL),
            'status' => $this->statusArg(in_array('v', $this->args['flags'])),
            ''       => $this->listArg(),
            default  => self::printWarning("Unknown action (use list|start|stop|restart|status)."),
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

        $this->printListLabel("Scheduled Tasks", self::CL);
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
        $scheduler = $this->scheduler();
        $info = $scheduler::status();
        if ($info && !$this->isDownRecord($info)) {
            self::printWarning("Scheduler already running [PID:{$info->pid}] ({$info->getStartedAt()}).");
            return;
        }

        // While the application runs, it starts the scheduler — in the background, with
        // or without -d. Not declared, it runs until stopped: on for a while, then off.
        if ($this->startThroughApplication($scheduler, 'schedule', static fn(): int => $scheduler::dispatch(), self::CL)) {
            return;
        }

        if (in_array('d', $this->args['flags'])) {
            $pid = $scheduler::dispatch();
            $info = null;
            for ($i = 0; $i < 20 && $info === null; $i++) {
                usleep(50_000);
                $info = $scheduler::status();
            }
            self::printSuccess("Scheduler dispatched (background).");
            self::printKeyValue("PID", (string) ($info->pid ?? $pid), 12, self::CL, 32);
            return;
        }

        self::printInfo("Starting scheduler …");
        $scheduler::start();
        self::printSuccess("Scheduler finished.");
    }

    private function stopArg(): void
    {
        $scheduler = $this->scheduler();
        $info = $scheduler::status();
        if (!$info || $this->isDownRecord($info)) {
            self::printWarning("Scheduler is not running.");
            return;
        }
        if ($scheduler::stop()) {
            self::printSuccess("Stop signal sent.");
            self::printKeyValue("PID", (string) $info->pid, 12, self::CL, 32);
        } else {
            self::printWarning("Failed to signal scheduler.");
        }
    }

    private function statusArg(bool $detailed): void
    {
        $scheduler = $this->scheduler();
        $info = $scheduler::status($detailed);

        $this->printListLabel("Scheduler Status", self::CL);
        if ($info && $this->isDownRecord($info)) {
            $this->printDownStatus(str_replace('\\', '.', $scheduler), $info, 'schedule', self::CL);
            self::printLabel("Scheduler Status", self::CL);
            return;
        }
        if (!$info) {
            self::printBadge('Scheduler', '○ STOPPED', self::CL, 31);
            self::printInfo("The scheduler is not running.");
            self::printLabel("Scheduler Status", self::CL);
            return;
        }

        self::printBadge('Scheduler', 'Scheduler ● ' . $info->state->name, self::CL, 32);
        self::printDivider();
        self::printKeyValue("PID", (string) $info->pid, 12, self::CL, 36);
        $this->printOwnership($scheduler, $info, self::CL);
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
     * The scheduler class: the one the application declares, when it declares its own;
     * the built-in one otherwise.
     *
     * @return class-string<Scheduler>
     */
    private function scheduler(): string
    {
        foreach ($this->application()['record']?->components ?? [] as $component) {
            if (($component['kind'] ?? null) === 'Scheduler' && is_string($component['class'] ?? null)) {
                return $component['class'];
            }
        }
        return Scheduler::class;
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
        self::printBadge('restart', 'stop and start again', $cl, 36);
        self::printBadge('status', 'scheduler run state + task count', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);
        self::printInfo("The scheduler fires one process per host (singleton lock).");
        self::printInfo("While the application runs, it starts the scheduler (start, with or without -d):");
        self::printInfo("  declared — with its settings; not declared — runs until stopped, never restarted.");

        self::printDivider($cl);

        self::printInfo("Docs: https://winterframe.net/docs/cmd-schedule");


        self::printTitle("Schedule Help", $cl);
    }
}
