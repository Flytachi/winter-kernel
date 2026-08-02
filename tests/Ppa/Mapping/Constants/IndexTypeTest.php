<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Constants;

use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexType;
use PHPUnit\Framework\TestCase;

final class IndexTypeTest extends TestCase
{
    public function test_is_string_backed_enum(): void
    {
        self::assertSame('string', (new \ReflectionEnum(IndexType::class))->getBackingType()?->getName());
    }

    public function test_cases_and_values(): void
    {
        self::assertSame('PRIMARY', IndexType::PRIMARY->value);
        self::assertSame('INDEX', IndexType::INDEX->value);
        self::assertSame('UNIQUE', IndexType::UNIQUE->value);
    }

    public function test_exactly_three_cases(): void
    {
        self::assertCount(3, IndexType::cases());
    }
}
