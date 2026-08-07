<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\Daemon\DaemonConfigException;
use Flytachi\Winter\Kernel\Process\InterruptedException;
use Flytachi\Winter\Kernel\Process\ProcessAlreadyRunningException;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public function test_process_already_running_is_a_runtime_exception(): void
    {
        $e = new ProcessAlreadyRunningException('running');
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('running', $e->getMessage());
    }

    public function test_interrupted_is_a_runtime_exception(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new InterruptedException());
    }

    public function test_daemon_config_is_a_runtime_exception(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new DaemonConfigException('bad'));
    }

    public function test_are_throwable_and_catchable(): void
    {
        try {
            throw new DaemonConfigException('no body');
        } catch (DaemonConfigException $e) {
            self::assertSame('no body', $e->getMessage());
            return;
        }

        self::fail('exception was not caught');
    }
}
