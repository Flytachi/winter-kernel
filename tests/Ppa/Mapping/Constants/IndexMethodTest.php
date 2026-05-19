<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Constants;

use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexMethod;
use PHPUnit\Framework\TestCase;

final class IndexMethodTest extends TestCase
{
    public function test_is_string_backed_enum(): void
    {
        self::assertSame('string', (new \ReflectionEnum(IndexMethod::class))->getBackingType()?->getName());
    }

    public function test_cases_and_values(): void
    {
        self::assertSame('BTREE', IndexMethod::BTREE->value);
        self::assertSame('HASH', IndexMethod::HASH->value);
        self::assertSame('GIST', IndexMethod::GIST->value);
        self::assertSame('GIN', IndexMethod::GIN->value);
    }

    public function test_exactly_four_cases(): void
    {
        self::assertCount(4, IndexMethod::cases());
    }
}
