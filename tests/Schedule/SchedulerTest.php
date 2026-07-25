<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule;

use Flytachi\Winter\K2\Concurrent\CompletableFuture;
use Flytachi\Winter\K2\Schedule\Scheduler;
use Flytachi\Winter\K2\Schedule\ScheduledTask;
use Flytachi\Winter\K2\Schedule\Trigger\FixedDelayTrigger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit-tests the scheduler's deterministic decision helpers (seed / untilNext) in
 * isolation, via reflection — no runtime, no forks.
 */
final class SchedulerTest extends TestCase
{
    private function task(float $initialDelay = 0.0): ScheduledTask
    {
        return new ScheduledTask('App\\Svc', 'run', new FixedDelayTrigger(5.0), $initialDelay);
    }

    /**
     * @param ScheduledTask[] $tasks
     */
    private function withTasks(array $tasks): Scheduler
    {
        $scheduler = new Scheduler();
        new ReflectionProperty(Scheduler::class, 'tasks')->setValue($scheduler, $tasks);
        return $scheduler;
    }

    private function untilNext(Scheduler $scheduler, float $now): float
    {
        return new ReflectionMethod(Scheduler::class, 'untilNext')->invoke($scheduler, $now);
    }

    public function test_seed_sets_first_fire_from_now_plus_initial_delay(): void
    {
        $a = $this->task(0.0);
        $b = $this->task(2.5);
        $scheduler = $this->withTasks([$a, $b]);

        new ReflectionMethod(Scheduler::class, 'seed')->invoke($scheduler, 100.0);

        self::assertSame(100.0, $a->nextFireAt);
        self::assertSame(102.5, $b->nextFireAt);
    }

    public function test_until_next_idles_max_when_no_tasks(): void
    {
        // MAX_SLEEP = 1.0
        self::assertSame(1.0, $this->untilNext($this->withTasks([]), 100.0));
    }

    public function test_until_next_idles_max_when_all_in_flight(): void
    {
        $t = $this->task();
        $t->nextFireAt = 100.0; // due, but in flight → excluded
        $t->inFlight = true;
        self::assertSame(1.0, $this->untilNext($this->withTasks([$t]), 100.0));
    }

    public function test_until_next_floors_a_past_due_task(): void
    {
        // MIN_SLEEP = 0.01
        $t = $this->task();
        $t->nextFireAt = 90.0; // overdue
        self::assertSame(0.01, $this->untilNext($this->withTasks([$t]), 100.0));
    }

    public function test_until_next_caps_a_far_future_task(): void
    {
        $t = $this->task();
        $t->nextFireAt = 200.0; // far away → capped at MAX_SLEEP
        self::assertSame(1.0, $this->untilNext($this->withTasks([$t]), 100.0));
    }

    public function test_until_next_returns_soonest_within_the_window(): void
    {
        $a = $this->task();
        $a->nextFireAt = 100.7;
        $b = $this->task();
        $b->nextFireAt = 100.3; // sooner
        self::assertEqualsWithDelta(0.3, $this->untilNext($this->withTasks([$a, $b]), 100.0), 1e-9);
    }

    public function test_until_next_polls_soon_while_a_run_is_in_flight(): void
    {
        // Nothing is due for a while, but a run is in flight — the loop must poll
        // soon (REAP_POLL = 0.05) to reap it, not idle a whole MAX_SLEEP.
        $t = $this->task();
        $t->nextFireAt = 200.0;
        $scheduler = $this->withTasks([$t]);
        new ReflectionProperty(Scheduler::class, 'running')->setValue($scheduler, [
            0 => CompletableFuture::completedFuture(null),
        ]);
        self::assertSame(0.05, $this->untilNext($scheduler, 100.0));
    }

    public function test_reap_finalizes_only_settled_runs(): void
    {
        // Task 0's run has completed; task 1's is still in flight.
        $done = $this->task();
        $done->inFlight = true;
        $done->lastStartAt = 100.0;
        $pending = $this->task();
        $pending->inFlight = true;
        $pending->lastStartAt = 100.0;

        $scheduler = $this->withTasks([$done, $pending]);
        new ReflectionProperty(Scheduler::class, 'running')->setValue($scheduler, [
            0 => CompletableFuture::completedFuture(null),
            1 => new CompletableFuture(),
        ]);

        new ReflectionMethod(Scheduler::class, 'reap')->invoke($scheduler);

        // The settled run is finalized: released, counted, its next fire advanced.
        self::assertFalse($done->inFlight);
        self::assertSame(1, $done->runs);
        self::assertNotNull($done->lastEndAt);
        self::assertGreaterThan(0.0, $done->nextFireAt);

        // The pending run is untouched and still tracked.
        self::assertTrue($pending->inFlight);
        self::assertSame(0, $pending->runs);

        $running = new ReflectionProperty(Scheduler::class, 'running')->getValue($scheduler);
        self::assertArrayNotHasKey(0, $running);
        self::assertArrayHasKey(1, $running);
    }
}
