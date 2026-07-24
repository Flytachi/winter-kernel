<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Daemon;

use Flytachi\Winter\K2\Process\Activity;
use Flytachi\Winter\K2\Process\Daemon\Daemon;
use Flytachi\Winter\K2\Process\Daemon\ScalingPolicy;
use Flytachi\Winter\K2\Process\Daemon\Slot;
use Flytachi\Winter\K2\Process\Daemon\SlotState;
use Flytachi\Winter\K2\Tests\Process\Fixtures\StubDaemon;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the fleet-supervision decision algorithms (back-off, scaling damping,
 * victim selection, slot accounting) — the `SupervisesFleet` trait mixed into
 * Daemon — directly, without forking any process.
 */
final class SupervisesFleetTest extends TestCase
{
    private StubDaemon $daemon;

    protected function setUp(): void
    {
        $this->daemon = new StubDaemon();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->daemon, $method))->invoke($this->daemon, ...$args);
    }

    /** @param array<int, Slot> $slots */
    private function setSlots(array $slots): void
    {
        // The property is declared by the SupervisesFleet trait on Daemon, so it
        // must be reflected via the declaring class, not the StubDaemon subclass.
        (new \ReflectionProperty(Daemon::class, 'slots'))->setValue($this->daemon, $slots);
    }

    /** @param list<array{0: float, 1: int}> $history */
    private function setHistory(array $history): void
    {
        (new \ReflectionProperty(Daemon::class, 'desiredHistory'))->setValue($this->daemon, $history);
    }

    private function slot(int $index, SlotState $state, Activity $activity = Activity::IDLE): Slot
    {
        $s = new Slot($index);
        $s->state = $state;
        $s->activity = $activity;
        return $s;
    }

    // --- back-off ----------------------------------------------------------

    public function test_backoff_is_exponential(): void
    {
        self::assertSame(1.0, $this->call('backoff', 1.0, 1));
        self::assertSame(2.0, $this->call('backoff', 1.0, 2));
        self::assertSame(4.0, $this->call('backoff', 1.0, 3));
        self::assertSame(8.0, $this->call('backoff', 1.0, 4));
        self::assertSame(1.0, $this->call('backoff', 0.5, 2));   // 0.5 * 2^1
    }

    public function test_backoff_is_capped_at_thirty_seconds(): void
    {
        self::assertSame(30.0, $this->call('backoff', 1.0, 10));   // 512 → capped
    }

    public function test_backoff_zero_for_no_failures_or_no_base(): void
    {
        self::assertSame(0.0, $this->call('backoff', 1.0, 0));
        self::assertSame(0.0, $this->call('backoff', 0.0, 3));
    }

    // --- windowExtreme (the stabilization core) ---------------------------

    public function test_window_extreme_max_over_window_excludes_aged_entries(): void
    {
        $now = microtime(true);
        // An old high reading (100s ago) plus recent low readings.
        $this->setHistory([[$now - 100.0, 8], [$now - 1.0, 4], [$now, 4]]);

        // 60s window: the old 8 is aged out → high-water is 4.
        self::assertSame(4, $this->call('windowExtreme', $now - 60.0, true));
        // 200s window: the 8 is still in → high-water is 8 (no shrink yet).
        self::assertSame(8, $this->call('windowExtreme', $now - 200.0, true));
    }

    public function test_window_extreme_min_over_window(): void
    {
        $now = microtime(true);
        $this->setHistory([[$now - 5.0, 9], [$now - 1.0, 3], [$now, 6]]);
        self::assertSame(3, $this->call('windowExtreme', $now - 10.0, false));
    }

    // --- effectiveDesired (asymmetric damping) ----------------------------

    public function test_scale_up_reacts_to_the_raw_target(): void
    {
        $this->daemon->desired = 8;
        // committed = 1, raw = 8 → grow to 8.
        self::assertSame(8, $this->call('effectiveDesired', 1, ScalingPolicy::default()));
    }

    public function test_stable_when_raw_equals_committed(): void
    {
        $this->daemon->desired = 5;
        self::assertSame(5, $this->call('effectiveDesired', 5, ScalingPolicy::default()));
    }

    public function test_scale_down_when_low_demand_is_the_only_reading(): void
    {
        $this->daemon->desired = 4;
        // committed = 8, the only reading is 4 → shrink to 4.
        self::assertSame(4, $this->call('effectiveDesired', 8, ScalingPolicy::default()));
    }

    public function test_scale_down_is_held_off_while_a_recent_high_reading_stands(): void
    {
        $policy = new ScalingPolicy(scaleDownStabilization: 60.0);

        $this->daemon->desired = 8;
        $this->call('effectiveDesired', 8, $policy);   // records a high reading (8)

        // Demand drops to 4, but the high-water over the stabilization window is
        // still 8, so the fleet does NOT shrink yet.
        $this->daemon->desired = 4;
        self::assertSame(8, $this->call('effectiveDesired', 8, $policy));
    }

    // --- victim selection (IDLE-first, then highest slot) ------------------

    public function test_pick_victims_prefers_idle_then_highest_index(): void
    {
        $this->setSlots([
            $this->slot(0, SlotState::RUNNING, Activity::BUSY),
            $this->slot(1, SlotState::RUNNING, Activity::IDLE),
            $this->slot(2, SlotState::RUNNING, Activity::IDLE),
            $this->slot(3, SlotState::RUNNING, Activity::BUSY),
            $this->slot(4, SlotState::RETIRING, Activity::IDLE),  // not a candidate
            $this->slot(5, SlotState::EMPTY),                     // not a candidate
        ]);

        $indexes = array_map(static fn(Slot $s) => $s->index, $this->call('pickVictims', 2));
        // Two IDLE workers, highest index first.
        self::assertSame([2, 1], $indexes);
    }

    public function test_pick_victims_falls_back_to_busy_by_highest_index(): void
    {
        $this->setSlots([
            $this->slot(0, SlotState::RUNNING, Activity::BUSY),
            $this->slot(1, SlotState::RUNNING, Activity::IDLE),
            $this->slot(2, SlotState::RUNNING, Activity::BUSY),
        ]);

        $indexes = array_map(static fn(Slot $s) => $s->index, $this->call('pickVictims', 3));
        // IDLE #1 first, then BUSY by highest index (#2 then #0).
        self::assertSame([1, 2, 0], $indexes);
    }

    public function test_pick_victims_zero_or_no_candidates_returns_empty(): void
    {
        $this->setSlots([$this->slot(0, SlotState::RETIRING), $this->slot(1, SlotState::EMPTY)]);
        self::assertSame([], $this->call('pickVictims', 2));

        $this->setSlots([$this->slot(0, SlotState::RUNNING)]);
        self::assertSame([], $this->call('pickVictims', 0));
    }

    // --- slot accounting ---------------------------------------------------

    public function test_committed_count_includes_starting_running_restarting_retired(): void
    {
        $this->setSlots([
            $this->slot(0, SlotState::STARTING),
            $this->slot(1, SlotState::RUNNING),
            $this->slot(2, SlotState::RESTARTING),
            $this->slot(3, SlotState::RETIRED),
            $this->slot(4, SlotState::RETIRING),   // not committed
            $this->slot(5, SlotState::KILLING),    // not committed
            $this->slot(6, SlotState::EMPTY),      // not committed
        ]);

        self::assertSame(4, $this->call('committedCount'));
    }

    public function test_alive_count_includes_states_with_a_live_process(): void
    {
        $this->setSlots([
            $this->slot(0, SlotState::STARTING),
            $this->slot(1, SlotState::RUNNING),
            $this->slot(2, SlotState::RETIRING),
            $this->slot(3, SlotState::KILLING),
            $this->slot(4, SlotState::RESTARTING),  // not alive
            $this->slot(5, SlotState::RETIRED),     // not alive
            $this->slot(6, SlotState::EMPTY),       // not alive
        ]);

        self::assertSame(4, $this->call('aliveCount'));
    }

    public function test_next_free_index_returns_lowest_empty_slot(): void
    {
        $this->setSlots([
            $this->slot(0, SlotState::RUNNING),
            $this->slot(1, SlotState::EMPTY),
            $this->slot(2, SlotState::RUNNING),
        ]);
        self::assertSame(1, $this->call('nextFreeIndex'));
    }

    public function test_next_free_index_appends_when_all_occupied(): void
    {
        $this->setSlots([
            $this->slot(0, SlotState::RUNNING),
            $this->slot(1, SlotState::RUNNING),
        ]);
        self::assertSame(2, $this->call('nextFreeIndex'));
    }
}
