<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Constants;

use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\MigratablePriority;
use PHPUnit\Framework\TestCase;

final class MigratablePriorityTest extends TestCase
{
    public function test_is_int_backed_enum(): void
    {
        self::assertSame('int', (new \ReflectionEnum(MigratablePriority::class))->getBackingType()?->getName());
    }

    public function test_known_cases_and_values(): void
    {
        self::assertSame(0, MigratablePriority::High->value);
        self::assertSame(50, MigratablePriority::Normal->value);
        self::assertSame(100, MigratablePriority::Low->value);
    }

    public function test_exactly_three_cases_exist(): void
    {
        $names = array_map(static fn ($c): string => $c->name, MigratablePriority::cases());
        sort($names);
        self::assertSame(['High', 'Low', 'Normal'], $names);
    }

    public function test_ascending_sort_orders_high_first(): void
    {
        $cases = [
            MigratablePriority::Low,
            MigratablePriority::High,
            MigratablePriority::Normal,
        ];
        usort($cases, static fn ($a, $b): int => $a->value <=> $b->value);

        self::assertSame(
            [MigratablePriority::High, MigratablePriority::Normal, MigratablePriority::Low],
            $cases,
        );
    }

    public function test_from_int_round_trips(): void
    {
        self::assertSame(MigratablePriority::High, MigratablePriority::from(0));
        self::assertSame(MigratablePriority::Normal, MigratablePriority::from(50));
        self::assertSame(MigratablePriority::Low, MigratablePriority::from(100));
    }
}
