<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Daemon;

use Flytachi\Winter\K2\Process\Activity;
use Flytachi\Winter\K2\Process\Daemon\DaemonStatus;
use Flytachi\Winter\K2\Process\Daemon\SlotState;
use Flytachi\Winter\K2\Process\Daemon\WorkerStatus;
use Flytachi\Winter\K2\Process\ProcessState;
use Flytachi\Winter\K2\Process\ProcessStatus;
use PHPUnit\Framework\TestCase;

final class DaemonStatusTest extends TestCase
{
    private function make(array $workers = [], int $restarts = 0): DaemonStatus
    {
        return new DaemonStatus(
            pid: 900,
            className: 'App\\Fleet',
            state: ProcessState::RUNNING,
            activity: Activity::BUSY,
            startedAt: time() - 12,
            concurrency: 4,
            restarts: $restarts,
            workers: $workers,
        );
    }

    public function test_is_a_process_status(): void
    {
        self::assertInstanceOf(ProcessStatus::class, $this->make());
    }

    public function test_daemon_fields(): void
    {
        $workers = [new WorkerStatus(0, 11, SlotState::RUNNING, Activity::BUSY, time(), 0)];
        $status = $this->make($workers, 7);

        self::assertSame(7, $status->restarts);
        self::assertSame($workers, $status->workers);
    }

    public function test_json_merges_base_and_daemon_fields(): void
    {
        $workers = [
            new WorkerStatus(0, 11, SlotState::RUNNING, Activity::BUSY, time(), 0),
            new WorkerStatus(1, 12, SlotState::RETIRING, Activity::IDLE, time(), 1),
        ];
        $json = $this->make($workers, 3)->jsonSerialize();

        // base keys still present
        self::assertArrayHasKey('pid', $json);
        self::assertArrayHasKey('state', $json);
        self::assertArrayHasKey('heartbeat_at', $json);
        // daemon-only keys added
        self::assertSame(3, $json['restarts']);
        self::assertCount(2, $json['workers']);
        self::assertSame($workers, $json['workers']);
    }

    public function test_encodes_to_json_string_with_nested_workers(): void
    {
        $workers = [new WorkerStatus(0, 11, SlotState::RUNNING, Activity::BUSY, time(), 0)];
        $decoded = json_decode((string) json_encode($this->make($workers, 1)), true);

        self::assertSame(1, $decoded['restarts']);
        self::assertSame('running', $decoded['workers'][0]['state']);
        self::assertSame(11, $decoded['workers'][0]['pid']);
    }
}
