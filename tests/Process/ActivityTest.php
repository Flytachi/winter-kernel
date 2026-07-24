<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process;

use Flytachi\Winter\K2\Process\Activity;
use PHPUnit\Framework\TestCase;

final class ActivityTest extends TestCase
{
    public function test_is_string_backed_enum(): void
    {
        self::assertSame('string', (new \ReflectionEnum(Activity::class))->getBackingType()?->getName());
    }

    public function test_cases_and_values(): void
    {
        self::assertSame('idle', Activity::IDLE->value);
        self::assertSame('busy', Activity::BUSY->value);
    }

    public function test_exactly_two_cases(): void
    {
        self::assertCount(2, Activity::cases());
    }

    public function test_from_value_round_trips(): void
    {
        self::assertSame(Activity::BUSY, Activity::from('busy'));
        self::assertSame(Activity::IDLE, Activity::from('idle'));
    }
}
