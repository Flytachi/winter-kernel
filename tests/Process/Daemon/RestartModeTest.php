<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Daemon;

use Flytachi\Winter\K2\Process\Daemon\RestartMode;
use PHPUnit\Framework\TestCase;

final class RestartModeTest extends TestCase
{
    public function test_is_pure_enum(): void
    {
        self::assertNull((new \ReflectionEnum(RestartMode::class))->getBackingType());
    }

    public function test_exactly_three_cases(): void
    {
        self::assertCount(3, RestartMode::cases());
        self::assertSame(['ALWAYS', 'ON_FAILURE', 'NEVER'], array_map(
            static fn(RestartMode $m) => $m->name,
            RestartMode::cases()
        ));
    }

    public function test_always_restarts_on_any_exit(): void
    {
        self::assertTrue(RestartMode::ALWAYS->shouldRestart(true));
        self::assertTrue(RestartMode::ALWAYS->shouldRestart(false));
    }

    public function test_on_failure_restarts_only_on_crash(): void
    {
        self::assertTrue(RestartMode::ON_FAILURE->shouldRestart(true));
        self::assertFalse(RestartMode::ON_FAILURE->shouldRestart(false));
    }

    public function test_never_restarts(): void
    {
        self::assertFalse(RestartMode::NEVER->shouldRestart(true));
        self::assertFalse(RestartMode::NEVER->shouldRestart(false));
    }
}
