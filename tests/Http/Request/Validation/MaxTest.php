<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Max;
use PHPUnit\Framework\TestCase;

class MaxTest extends TestCase
{
    // ── null passthrough ──────────────────────────────────────────────────────

    public function testNullPasses(): void
    {
        self::assertNull((new Max(100))->validate(null, 'field'));
    }

    // ── int ───────────────────────────────────────────────────────────────────

    public function testIntBelowMaxPasses(): void
    {
        self::assertNull((new Max(100))->validate(50, 'field'));
    }

    public function testIntAtMaxPasses(): void
    {
        self::assertNull((new Max(100))->validate(100, 'field'));
    }

    public function testIntAboveMaxFails(): void
    {
        self::assertSame('must not exceed 100', (new Max(100))->validate(101, 'field'));
    }

    // ── float ─────────────────────────────────────────────────────────────────

    public function testFloatBelowMaxPasses(): void
    {
        self::assertNull((new Max(999.99))->validate(500.0, 'field'));
    }

    public function testFloatAtMaxPasses(): void
    {
        self::assertNull((new Max(999.99))->validate(999.99, 'field'));
    }

    public function testFloatAboveMaxFails(): void
    {
        self::assertSame('must not exceed 999.99', (new Max(999.99))->validate(1000.0, 'field'));
    }

    // ── BcMath\Number ─────────────────────────────────────────────────────────

    public function testBcMathNumberBelowMaxPasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull((new Max(1000))->validate(new \BcMath\Number('999'), 'field'));
    }

    public function testBcMathNumberAtMaxPasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull((new Max(1000))->validate(new \BcMath\Number('1000'), 'field'));
    }

    public function testBcMathNumberAboveMaxFails(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertSame('must not exceed 1000', (new Max(1000))->validate(new \BcMath\Number('1001'), 'field'));
    }

    // ── non-numeric types skipped ─────────────────────────────────────────────

    public function testStringIsSkipped(): void
    {
        self::assertNull((new Max(100))->validate('hello', 'field'));
    }

    public function testArrayIsSkipped(): void
    {
        self::assertNull((new Max(100))->validate([1, 2], 'field'));
    }
}
