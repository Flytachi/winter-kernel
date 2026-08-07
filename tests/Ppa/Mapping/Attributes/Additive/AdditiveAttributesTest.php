<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Attributes\Additive;

use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Additive\NullableIs;
use PHPUnit\Framework\TestCase;

final class AdditiveAttributesTest extends TestCase
{
    // ── DefaultVal — sets $default by reference, leaves $nullable alone ──────

    public function test_default_val_sets_default_to_provided_definition(): void
    {
        $nullable = null;
        $default = null;
        (new DefaultVal('CURRENT_TIMESTAMP'))->preparation($nullable, $default);
        self::assertSame('CURRENT_TIMESTAMP', $default);
        self::assertNull($nullable);
    }

    public function test_default_val_overwrites_previous_default(): void
    {
        $nullable = null;
        $default = 'old';
        (new DefaultVal('new'))->preparation($nullable, $default);
        self::assertSame('new', $default);
    }

    public function test_default_val_allows_arbitrary_sql_expression(): void
    {
        $nullable = null;
        $default = null;
        (new DefaultVal("'pending'"))->preparation($nullable, $default);
        self::assertSame("'pending'", $default);
    }

    // ── NullableIs — sets $nullable by reference, leaves $default alone ──────

    public function test_nullable_is_default_true(): void
    {
        $nullable = null;
        $default = null;
        (new NullableIs())->preparation($nullable, $default);
        self::assertTrue($nullable);
        self::assertNull($default);
    }

    public function test_nullable_is_false_marks_not_null(): void
    {
        $nullable = null;
        $default = null;
        (new NullableIs(false))->preparation($nullable, $default);
        self::assertFalse($nullable);
    }

    public function test_nullable_is_overwrites_previous_value(): void
    {
        $nullable = true;
        $default = null;
        (new NullableIs(false))->preparation($nullable, $default);
        self::assertFalse($nullable);
    }

    public function test_two_additives_compose_independently(): void
    {
        $nullable = null;
        $default = null;
        (new NullableIs(false))->preparation($nullable, $default);
        (new DefaultVal('0'))->preparation($nullable, $default);

        self::assertFalse($nullable);
        self::assertSame('0', $default);
    }
}
