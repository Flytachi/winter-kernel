<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Daemon;

use Flytachi\Winter\Kernel\Process\Daemon\SlotState;
use PHPUnit\Framework\TestCase;

final class SlotStateTest extends TestCase
{
    public function test_is_string_backed_enum(): void
    {
        self::assertSame('string', (new \ReflectionEnum(SlotState::class))->getBackingType()?->getName());
    }

    public function test_all_cases_present(): void
    {
        $values = array_map(static fn(SlotState $s) => $s->value, SlotState::cases());
        self::assertSame(
            ['empty', 'starting', 'running', 'retiring', 'killing', 'restarting', 'retired'],
            $values
        );
    }

    /**
     * isCommitted = counts toward the fleet size reconcile drives to desired.
     */
    public function test_is_committed_matrix(): void
    {
        self::assertTrue(SlotState::STARTING->isCommitted());
        self::assertTrue(SlotState::RUNNING->isCommitted());
        self::assertTrue(SlotState::RESTARTING->isCommitted());
        self::assertTrue(SlotState::RETIRED->isCommitted());

        self::assertFalse(SlotState::EMPTY->isCommitted());
        self::assertFalse(SlotState::RETIRING->isCommitted());
        self::assertFalse(SlotState::KILLING->isCommitted());
    }

    /**
     * isAlive = has a live OS process attached.
     */
    public function test_is_alive_matrix(): void
    {
        self::assertTrue(SlotState::STARTING->isAlive());
        self::assertTrue(SlotState::RUNNING->isAlive());
        self::assertTrue(SlotState::RETIRING->isAlive());
        self::assertTrue(SlotState::KILLING->isAlive());

        self::assertFalse(SlotState::EMPTY->isAlive());
        self::assertFalse(SlotState::RESTARTING->isAlive());
        self::assertFalse(SlotState::RETIRED->isAlive());
    }

    /**
     * A committed-but-not-alive state (RESTARTING/RETIRED) is exactly what keeps
     * reconcile from refilling a slot the restart path already owns.
     */
    public function test_restarting_and_retired_are_committed_but_not_alive(): void
    {
        foreach ([SlotState::RESTARTING, SlotState::RETIRED] as $state) {
            self::assertTrue($state->isCommitted(), $state->value);
            self::assertFalse($state->isAlive(), $state->value);
        }
    }
}
