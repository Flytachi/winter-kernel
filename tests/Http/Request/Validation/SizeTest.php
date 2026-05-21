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

    // ── string (exact) ───────────────────────────────────────────────────────

    public function testStringExactMatchPasses(): void
    {
        self::assertNull((new Size(5))->validate('hello', 'field'));
    }

    public function testStringExactMismatchFails(): void
    {
        self::assertSame('must be exactly 3', (new Size(3))->validate('hello', 'field'));
    }

    public function testUnicodeStringLength(): void
    {
        self::assertNull((new Size(3))->validate('日本語', 'field')); // 3 chars
    }

    // ── string (range) ───────────────────────────────────────────────────────

    public function testStringInRangePasses(): void
    {
        self::assertNull((new Size(min: 2, max: 10))->validate('hello', 'field'));
    }

    public function testStringBelowRangeFails(): void
    {
        self::assertSame(
            'size must be between 5 and 10',
            (new Size(min: 5, max: 10))->validate('hi', 'field'),
        );
    }

    public function testStringAboveRangeFails(): void
    {
        self::assertSame(
            'size must be between 1 and 3',
            (new Size(min: 1, max: 3))->validate('hello', 'field'),
        );
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function testArrayExactPasses(): void
    {
        self::assertNull((new Size(3))->validate([1, 2, 3], 'field'));
    }

    public function testArrayExactFails(): void
    {
        self::assertSame('must be exactly 2', (new Size(2))->validate([1, 2, 3], 'field'));
    }

    public function testArrayInRangePasses(): void
    {
        self::assertNull((new Size(min: 1, max: 5))->validate([1, 2], 'field'));
    }

    public function testEmptyArrayBelowMinFails(): void
    {
        self::assertSame(
            'size must be between 1 and 5',
            (new Size(min: 1, max: 5))->validate([], 'field'),
        );
    }

    // ── number (digit count) ──────────────────────────────────────────────────

    public function testIntDigitCountPasses(): void
    {
        self::assertNull((new Size(3))->validate(100, 'field')); // "100" → 3
    }

    public function testIntDigitCountFails(): void
    {
        self::assertSame('must be exactly 2', (new Size(2))->validate(100, 'field')); // "100" → 3
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
        self::assertSame('must be exactly 2', (new Size(2))->validate(new \BcMath\Number('100'), 'field'));
    }

    // ── unsupported types skipped ─────────────────────────────────────────────

    public function testBoolIsSkipped(): void
    {
        self::assertNull((new Size(1))->validate(true, 'field'));
    }

    // ── custom message ────────────────────────────────────────────────────────

    public function testCustomMessageOverridesDefault(): void
    {
        self::assertSame(
            'too long',
            (new Size(3, message: 'too long'))->validate('hello', 'field'),
        );
    }

    public function testCustomMessagePassesThroughOnSuccess(): void
    {
        self::assertNull(
            (new Size(5, message: 'too long'))->validate('hello', 'field'),
        );
    }

    public function testTranslationKeyMessageReturnedRaw(): void
    {
        // Constraint returns the raw '{key}' marker — Locale resolution
        // happens later in ParameterResolver, not here.
        self::assertSame(
            '{order.name_too_long}',
            (new Size(3, message: '{order.name_too_long}'))->validate('hello', 'field'),
        );
    }
}