<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule;

use Flytachi\Winter\K2\Schedule\ScheduledTask;
use Flytachi\Winter\K2\Schedule\Trigger\FixedDelayTrigger;
use PHPUnit\Framework\TestCase;

final class ScheduledTaskTest extends TestCase
{
    public function test_identity_and_initial_state(): void
    {
        $task = new ScheduledTask('App\\Report', 'flush', new FixedDelayTrigger(5.0), 1.5);

        self::assertSame('App\\Report::flush', $task->id());
        self::assertSame(1.5, $task->initialDelay);
        self::assertFalse($task->inFlight);
        self::assertSame(0, $task->runs);
        self::assertNull($task->lastStartAt);
        self::assertNull($task->lastEndAt);
        self::assertSame(0.0, $task->nextFireAt);
    }
}
