<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\ResourceUsage;
use PHPUnit\Framework\TestCase;

final class ResourceUsageTest extends TestCase
{
    public function test_of_pid_reads_the_current_process(): void
    {
        $usage = ResourceUsage::ofPid(getmypid());

        self::assertInstanceOf(ResourceUsage::class, $usage);
        self::assertSame(getmypid(), $usage->pid);
        self::assertIsInt($usage->ppid);
        self::assertIsString($usage->user);
        self::assertIsFloat($usage->cpu);
        self::assertIsFloat($usage->memory);
        self::assertIsInt($usage->rssKb);
        self::assertIsString($usage->elapsed);
        self::assertIsString($usage->command);
    }

    public function test_of_pid_returns_null_for_a_dead_pid(): void
    {
        // A PID far above any live process on a normal system.
        self::assertNull(ResourceUsage::ofPid(4_194_303));
    }

    public function test_rss_mb_converts_from_kb(): void
    {
        $usage = new ResourceUsage(1, 0, 'me', 0.0, 0.0, 2048, '00:01', 'php');
        self::assertSame(2.0, $usage->rssMb());
    }

    public function test_json_shape_and_types(): void
    {
        $json = (new ResourceUsage(7, 1, 'root', 1.5, 0.3, 1536, '01:23', 'php worker'))->jsonSerialize();

        self::assertSame(
            ['pid', 'ppid', 'user', 'cpu', 'memory', 'rss_mb', 'elapsed', 'command'],
            array_keys($json)
        );
        self::assertSame(7, $json['pid']);
        self::assertSame('root', $json['user']);
        self::assertSame(1.5, $json['cpu']);
        self::assertSame(1.5, $json['rss_mb']);   // 1536 KB rounded to 1 decimal
        self::assertSame('php worker', $json['command']);
    }

    public function test_encodes_to_json_string(): void
    {
        self::assertIsString(json_encode(new ResourceUsage(1, 0, 'me', 0.0, 0.0, 0, '0', 'x')));
    }
}
