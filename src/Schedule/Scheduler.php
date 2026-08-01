<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Schedule;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\K2\Concurrent\Future;
use Flytachi\Winter\K2\Core\ClassScanner;
use Flytachi\Winter\K2\Process\Process;

/**
 * The scheduling runtime — one system process that runs every {@see Scheduled}
 * method on its trigger.
 *
 * On boot it scans the project (and plugins) for annotated methods, then loops:
 * each due task is dispatched via {@see Process::spawn()} — a coroutine under
 * Swoole, a fork otherwise — capped by {@see $concurrency}, so a slow task never
 * delays another's timing. A task never overlaps itself (an in-flight run holds
 * the next fire), a throwing task is logged and never stops the scheduler, and the
 * per-class singleton lock inherited from {@see Process} guarantees one scheduler
 * per host — no double firing.
 *
 * Completion is observed from the parent through the {@see Future} that
 * {@see Process::spawn()} returns, not from inside the run — so the next fire and
 * the in-flight release happen correctly under the fork runtime too, where the run
 * itself executes in a separate child process.
 *
 * It is started like any process — foreground {@see start()}, background
 * {@see dispatch()}, and stopped with {@see stop()} — or through `call schedule`.
 * SIGHUP re-scans the annotated methods without a restart.
 */
class Scheduler extends Process
{
    /** Shortest idle pause between loop passes, in seconds (avoids a busy spin). */
    private const float MIN_SLEEP = 0.01;
    /** Longest pause between passes, in seconds: bounds the wake latency. */
    private const float MAX_SLEEP = 1.0;
    /** While runs are in flight, poll at least this often (seconds) to reap them promptly. */
    private const float REAP_POLL = 0.05;

    protected ?string $processTitle = 'Scheduler';

    /** @var ScheduledTask[] The live task registry, rebuilt on SIGHUP. */
    private array $tasks = [];
    /** @var array<int, Future> In-flight run per task index; reaped when the future settles. */
    private array $running = [];

    /**
     * Discovers the annotated methods and drives their triggers until stopped.
     */
    public function run(): void
    {
        $this->tasks = $this->discover();
        $this->seed(microtime(true));

        if ($this->tasks === []) {
            $this->logger->warning('Scheduler: no #[Scheduled] methods found; idling.');
        } else {
            $this->logger->info('Scheduler: ' . count($this->tasks) . ' scheduled task(s) registered.');
        }

        while ($this->isRunning()) {
            $this->reap();
            $now = microtime(true);
            foreach ($this->tasks as $index => $task) {
                if (!$task->inFlight && $task->nextFireAt <= $now) {
                    $this->fire($index, $task);
                }
            }
            $this->sleep($this->untilNext(microtime(true)));
        }
    }

    /**
     * SIGHUP: re-scan the annotated methods so newly added or removed tasks take
     * effect without a restart. Any in-flight runs are detached (they finish on
     * their own and harmlessly); the fresh registry seeds from now.
     */
    protected function onReload(): void
    {
        $this->tasks = $this->discover();
        $this->running = [];
        $this->seed(microtime(true));
        $this->logger->info('Scheduler: reloaded, ' . count($this->tasks) . ' scheduled task(s).');
    }

    /**
     * Dispatches one task: mark it in flight and spawn the run — resolve its class
     * from the container and invoke the method, logging any failure so it is never
     * fatal. Bean resolution and the method call are reported separately, so a
     * class that cannot be autowired is distinguished from a method that threw. The
     * run's completion is picked up later by {@see reap()}; the spawned closure
     * deliberately does not touch task state, because under the fork runtime it
     * executes in a separate process.
     */
    private function fire(int $index, ScheduledTask $task): void
    {
        $task->inFlight = true;
        $task->lastStartAt = microtime(true);

        $this->running[$index] = $this->spawn(function () use ($task): void {
            try {
                $bean = Container::getInstance()->make($task->className);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Scheduled ' . $task->id() . ': cannot resolve ' . $task->className
                    . ' from the container — check its constructor and #[Autowired] dependencies ('
                    . $e->getMessage() . ').'
                );
                return;
            }

            try {
                $bean->{$task->methodName}();
            } catch (\Throwable $e) {
                $this->logger->error('Scheduled ' . $task->id() . ' threw: ' . $e->getMessage());
            }
        });
    }

    /**
     * Finalises every run whose future has settled: record its end, advance the
     * next fire from the trigger, and release the in-flight hold. Reading
     * completion from the parent (not the run) keeps the state correct under the
     * fork runtime, where the run executes in a child process.
     */
    private function reap(): void
    {
        foreach ($this->running as $index => $future) {
            if (!$future->isDone()) {
                continue;
            }
            $task = $this->tasks[$index] ?? null;
            if ($task !== null) {
                $task->lastEndAt = microtime(true);
                $task->runs++;
                $task->nextFireAt = $task->trigger->nextFireTime(
                    microtime(true),
                    $task->lastStartAt,
                    $task->lastEndAt,
                );
                $task->inFlight = false;
            }
            unset($this->running[$index]);
        }
    }

    /**
     * Scans the project and plugins for {@see Scheduled} methods.
     *
     * Override to source tasks another way — e.g. from a database of cron rows —
     * instead of (or in addition to) annotation scanning.
     *
     * @return ScheduledTask[]
     */
    protected function discover(): array
    {
        $collector = new ScheduledCollector();
        ClassScanner::scan($collector);
        return $collector->getResult();
    }

    /**
     * Seeds each task's first fire time from its trigger — boot plus the initial
     * delay for a period trigger, the next matching instant for a cron trigger.
     */
    private function seed(float $now): void
    {
        foreach ($this->tasks as $task) {
            $task->nextFireAt = $task->trigger->firstFireTime($now, $task->initialDelay);
        }
    }

    /**
     * How long to pause before the next pass: until the soonest not-in-flight
     * task is due, clamped to [{@see MIN_SLEEP}, {@see MAX_SLEEP}]. When every task
     * is in flight (or there are none) it idles a full {@see MAX_SLEEP} — but while
     * any run is in flight the wait is capped to {@see REAP_POLL} so its completion,
     * and the task's next fire, are picked up promptly rather than a whole second later.
     */
    private function untilNext(float $now): float
    {
        $soonest = null;
        foreach ($this->tasks as $task) {
            if ($task->inFlight) {
                continue;
            }
            $soonest = $soonest === null ? $task->nextFireAt : min($soonest, $task->nextFireAt);
        }
        $wait = $soonest === null
            ? self::MAX_SLEEP
            : max(self::MIN_SLEEP, min($soonest - $now, self::MAX_SLEEP));

        if ($this->running !== []) {
            $wait = min($wait, self::REAP_POLL);
        }
        return $wait;
    }
}
