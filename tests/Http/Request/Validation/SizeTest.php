<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Size;
use PHPUnit\Framework\TestCase;

class SizeTest extends TestCase
{
    // ── null passthrough ──────────────────────────────────────────────────────

    public function testNullPasses(): void
    {
        self::assertNull((new Size(10))->validate(null, 'field'));
    }

    // ── string ───────────────────────────────────────────────────────────────

    public function testStringWithinMaxPasses(): void
    {
        self::assertNull((new Size(10))->validate('hello', 'field'));
    }

    public function testStringAtMaxPasses(): void
    {
        self::assertNull((new Size(5))->validate('hello', 'field'));
    }

    public function testStringExceedsMaxFails(): void
    {
        self::assertSame('must not exceed 3', (new Size(3))->validate('hello', 'field'));
    }

    public function testStringWithMinAndMaxInRangePasses(): void
    {
        self::assertNull((new Size(max: 10, min: 2))->validate('hello', 'field'));
    }

    public function testStringBelowMinFails(): void
    {
        self::assertSame('size must be between 5 and 10', (new Size(max: 10, min: 5))->validate('hi', 'field'));
    }

    public function testStringOnlyMinPasses(): void
    {
        self::assertNull((new Size(min: 3))->validate('hello', 'field'));
    }

    public function testStringOnlyMinFails(): void
    {
        self::assertSame('must be at least 5', (new Size(min: 5))->validate('hi', 'field'));
    }

    public function testUnicodeStringLength(): void
    {
        self::assertNull((new Size(3))->validate('日本語', 'field')); // 3 chars
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function testArrayWithinMaxPasses(): void
    {
        self::assertNull((new Size(5))->validate([1, 2, 3], 'field'));
    }

    public function testArrayExceedsMaxFails(): void
    {
        self::assertSame('must not exceed 2', (new Size(2))->validate([1, 2, 3], 'field'));
    }

    public function testArrayInRangePasses(): void
    {
        self::assertNull((new Size(max: 5, min: 1))->validate([1, 2], 'field'));
    }

    public function testEmptyArrayBelowMinFails(): void
    {
        self::assertSame('size must be between 1 and 5', (new Size(max: 5, min: 1))->validate([], 'field'));
    }

    // ── number (digit count) ──────────────────────────────────────────────────

    public function testIntDigitCountPasses(): void
    {
        self::assertNull((new Size(3))->validate(100, 'field')); // "100" → 3
    }

    public function testIntDigitCountFails(): void
    {
        self::assertSame('must not exceed 2', (new Size(2))->validate(100, 'field')); // "100" → 3
    }

    public function testSingleDigitPasses(): void
    {
        self::assertNull((new Size(1))->validate(5, 'field')); // "5" → 1
    }

    public function testBcMathDigitCount(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        // BcMath\Number("100") → "100" → length 3
        self::assertNull((new Size(3))->validate(new \BcMath\Number('100'), 'field'));
        self::assertSame('must not exceed 2', (new Size(2))->validate(new \BcMath\Number('100'), 'field'));
    }

    // ── unsupported types skipped ─────────────────────────────────────────────

    public function testBoolIsSkipped(): void
    {
        self::assertNull((new Size(1))->validate(true, 'field'));
    }
}
