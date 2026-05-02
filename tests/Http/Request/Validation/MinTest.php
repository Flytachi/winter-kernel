<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Min;
use PHPUnit\Framework\TestCase;

class MinTest extends TestCase
{
    // ── null passthrough ──────────────────────────────────────────────────────

    public function testNullPasses(): void
    {
        self::assertNull((new Min(1))->validate(null, 'field'));
    }

    // ── int ───────────────────────────────────────────────────────────────────

    public function testIntAboveMinPasses(): void
    {
        self::assertNull((new Min(1))->validate(5, 'field'));
    }

    public function testIntAtMinPasses(): void
    {
        self::assertNull((new Min(1))->validate(1, 'field'));
    }

    public function testIntBelowMinFails(): void
    {
        self::assertSame('must be at least 1', (new Min(1))->validate(0, 'field'));
    }

    public function testNegativeMinPasses(): void
    {
        self::assertNull((new Min(-10))->validate(-5, 'field'));
    }

    public function testNegativeMinFails(): void
    {
        self::assertSame('must be at least -10', (new Min(-10))->validate(-15, 'field'));
    }

    // ── float ─────────────────────────────────────────────────────────────────

    public function testFloatAboveMinPasses(): void
    {
        self::assertNull((new Min(0.01))->validate(0.5, 'field'));
    }

    public function testFloatAtMinPasses(): void
    {
        self::assertNull((new Min(0.01))->validate(0.01, 'field'));
    }

    public function testFloatBelowMinFails(): void
    {
        self::assertSame('must be at least 0.01', (new Min(0.01))->validate(0.0, 'field'));
    }

    // ── BcMath\Number ─────────────────────────────────────────────────────────

    public function testBcMathNumberAboveMinPasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull((new Min(1))->validate(new \BcMath\Number('5'), 'field'));
    }

    public function testBcMathNumberAtMinPasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull((new Min(1))->validate(new \BcMath\Number('1'), 'field'));
    }

    public function testBcMathNumberBelowMinFails(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertSame('must be at least 1', (new Min(1))->validate(new \BcMath\Number('0'), 'field'));
    }

    // ── non-numeric types skipped ─────────────────────────────────────────────

    public function testStringIsSkipped(): void
    {
        self::assertNull((new Min(1))->validate('hello', 'field'));
    }

    public function testArrayIsSkipped(): void
    {
        self::assertNull((new Min(1))->validate([1, 2], 'field'));
    }
}
