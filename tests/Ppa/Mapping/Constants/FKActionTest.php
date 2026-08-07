<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Constants;

use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\FKAction;
use PHPUnit\Framework\TestCase;

final class FKActionTest extends TestCase
{
    public function test_is_string_backed_enum(): void
    {
        self::assertSame('string', (new \ReflectionEnum(FKAction::class))->getBackingType()?->getName());
    }

    public function test_each_case_renders_to_the_expected_sql_token(): void
    {
        self::assertSame('RESTRICT', FKAction::RESTRICT->value);
        self::assertSame('NO ACTION', FKAction::NO_ACTION->value);
        self::assertSame('SET DEFAULT', FKAction::SET_DEFAULT->value);
        self::assertSame('SET NULL', FKAction::SET_NULL->value);
        self::assertSame('CASCADE', FKAction::CASCADE->value);
    }

    public function test_exactly_five_cases_exist(): void
    {
        self::assertCount(5, FKAction::cases());
    }
}
