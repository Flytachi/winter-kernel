<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Daemon;

use Flytachi\Winter\K2\Process\Activity;
use Flytachi\Winter\K2\Process\Daemon\SlotState;
use Flytachi\Winter\K2\Process\Daemon\WorkerStatus;
use PHPUnit\Framework\TestCase;

final class WorkerStatusTest extends TestCase
{
    public function test_fields(): void
    {
        $w = new WorkerStatus(
            slot: 1,
            pid: 5555,
            state: SlotState::RUNNING,
            activity: Activity::BUSY,
            startedAt: time() - 10,
            restarts: 2,
        );

        self::assertSame(1, $w->slot);
        self::assertSame(5555, $w->pid);
        self::assertSame(SlotState::RUNNING, $w->state);
        self::assertSame(Activity::BUSY, $w->activity);
        self::assertSame(2, $w->restarts);
    }

    public function test_json_shape_and_types(): void
    {
        $started = time() - 30;
        $json = (new WorkerStatus(3, 4321, SlotState::RETIRING, Activity::IDLE, $started, 1))->jsonSerialize();

        self::assertSame(
            ['slot', 'pid', 'state', 'activity', 'started_at', 'uptime', 'restarts'],
            array_keys($json)
        );
        self::assertSame(3, $json['slot']);
        self::assertSame(4321, $json['pid']);
        self::assertSame('retiring', $json['state']);       // SlotState->value
        self::assertSame('idle', $json['activity']);         // Activity->value
        self::assertSame($started, $json['started_at']);
        self::assertGreaterThanOrEqual(30, $json['uptime']);
        self::assertSame(1, $json['restarts']);
    }

    public function test_uptime_zero_when_not_started(): void
    {
        $json = (new WorkerStatus(0, 0, SlotState::RETIRED, Activity::IDLE, 0, 0))->jsonSerialize();
        self::assertSame(0, $json['uptime']);
    }

    public function test_encodes_to_json_string(): void
    {
        $w = new WorkerStatus(0, 10, SlotState::STARTING, Activity::IDLE, time(), 0);
        self::assertIsString(json_encode($w));
    }
}
