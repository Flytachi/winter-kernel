<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process;

use Flytachi\Winter\K2\Process\ProcessState;
use PHPUnit\Framework\TestCase;

final class ProcessStateTest extends TestCase
{
    public function test_is_int_backed_enum(): void
    {
        self::assertSame('int', (new \ReflectionEnum(ProcessState::class))->getBackingType()?->getName());
    }

    public function test_cases_and_ordinal_values(): void
    {
        self::assertSame(0, ProcessState::NEW->value);
        self::assertSame(1, ProcessState::RUNNING->value);
        self::assertSame(2, ProcessState::STOPPING->value);
        self::assertSame(3, ProcessState::TERMINATED->value);
        self::assertSame(4, ProcessState::FAILED->value);
        self::assertSame(5, ProcessState::RESTARTING->value);
    }

    public function test_exactly_six_cases(): void
    {
        self::assertCount(6, ProcessState::cases());
    }

    public function test_name_is_stable_label(): void
    {
        self::assertSame('RUNNING', ProcessState::RUNNING->name);
        self::assertSame('FAILED', ProcessState::FAILED->name);
    }
}
