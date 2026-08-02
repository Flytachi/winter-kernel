<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Daemon;

use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\Daemon\Slot;
use Flytachi\Winter\Kernel\Process\Daemon\SlotState;
use PHPUnit\Framework\TestCase;

final class SlotTest extends TestCase
{
    public function test_fresh_slot_defaults(): void
    {
        $slot = new Slot(2);

        self::assertSame(2, $slot->index);
        self::assertSame(SlotState::EMPTY, $slot->state);
        self::assertSame(0, $slot->pid);
        self::assertTrue(is_infinite($slot->deadline));
        self::assertSame(0.0, $slot->restartAt);
        self::assertSame(0, $slot->restarts);
        self::assertSame(0, $slot->startedAt);
        self::assertSame(Activity::IDLE, $slot->activity);
        self::assertSame(0, $slot->heartbeatAt);
        self::assertFalse($slot->killed);
    }

    public function test_index_is_readonly(): void
    {
        $prop = new \ReflectionProperty(Slot::class, 'index');
        self::assertTrue($prop->isReadOnly());
    }

    public function test_state_is_mutable_in_place(): void
    {
        $slot = new Slot(0);
        $slot->state = SlotState::RUNNING;
        $slot->pid = 4242;
        $slot->activity = Activity::BUSY;
        $slot->killed = true;

        self::assertSame(SlotState::RUNNING, $slot->state);
        self::assertSame(4242, $slot->pid);
        self::assertSame(Activity::BUSY, $slot->activity);
        self::assertTrue($slot->killed);
    }
}
