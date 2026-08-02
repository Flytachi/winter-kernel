<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use PHPUnit\Framework\TestCase;

final class ProcessStatusTest extends TestCase
{
    public function test_defaults(): void
    {
        $s = new ProcessStatus(100, 'Foo', ProcessState::RUNNING, Activity::IDLE, time());
        self::assertSame(0, $s->concurrency);
        self::assertNull($s->usage);
        self::assertSame(0, $s->heartbeatAt);
    }

    public function test_get_started_at_is_formatted(): void
    {
        $s = new ProcessStatus(1, 'Foo', ProcessState::RUNNING, Activity::IDLE, 1_600_000_000);
        self::assertSame(date('Y-m-d H:i:s P', 1_600_000_000), $s->getStartedAt());
    }

    public function test_json_shape_and_value_encoding(): void
    {
        $started = time() - 5;
        $json = (new ProcessStatus(
            pid: 200,
            className: 'App\\Worker',
            state: ProcessState::STOPPING,
            activity: Activity::BUSY,
            startedAt: $started,
            concurrency: 8,
            usage: null,
            heartbeatAt: $started + 3,
        ))->jsonSerialize();

        self::assertSame(
            ['pid', 'class', 'state', 'activity', 'started_at', 'uptime', 'concurrency', 'heartbeat_at', 'usage'],
            array_keys($json)
        );
        self::assertSame(200, $json['pid']);
        self::assertSame('App\\Worker', $json['class']);
        self::assertSame('STOPPING', $json['state']);      // ProcessState->name
        self::assertSame('busy', $json['activity']);        // Activity->value
        self::assertSame($started, $json['started_at']);
        self::assertGreaterThanOrEqual(5, $json['uptime']);
        self::assertSame(8, $json['concurrency']);
        self::assertSame($started + 3, $json['heartbeat_at']);
        self::assertNull($json['usage']);
    }

    /**
     * Regression: a backed Activity + JsonSerializable must encode cleanly — the
     * pure-enum activity previously made json_encode return false.
     */
    public function test_encodes_to_json_string(): void
    {
        $s = new ProcessStatus(1, 'Foo', ProcessState::RUNNING, Activity::BUSY, time());
        $encoded = json_encode($s);
        self::assertIsString($encoded);
        self::assertArrayHasKey('activity', json_decode($encoded, true));
    }
}
