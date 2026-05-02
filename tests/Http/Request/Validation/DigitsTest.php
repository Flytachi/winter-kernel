<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Digits;
use PHPUnit\Framework\TestCase;

class DigitsTest extends TestCase
{
    // ── null skipped ──────────────────────────────────────────────────────────

    public function testNullPasses(): void
    {
        self::assertNull((new Digits(6, 2))->validate(null, 'field'));
    }

    // ── integer part ──────────────────────────────────────────────────────────

    public function testIntegerWithinLimitPasses(): void
    {
        self::assertNull((new Digits(6))->validate(999999, 'field'));
    }

    public function testIntegerExceedsLimitFails(): void
    {
        self::assertSame(
            'integer part must not exceed 6 digits',
            (new Digits(6))->validate(1234567, 'field')
        );
    }

    public function testNegativeSignIgnored(): void
    {
        self::assertNull((new Digits(6))->validate(-999999, 'field'));
    }

    public function testNegativeExceedsLimitFails(): void
    {
        self::assertSame(
            'integer part must not exceed 6 digits',
            (new Digits(6))->validate(-1234567, 'field')
        );
    }

    public function testLeadingZerosNotCounted(): void
    {
        // "007" → significant digits = 1
        self::assertNull((new Digits(2))->validate('007', 'field'));
    }

    // ── fraction part ─────────────────────────────────────────────────────────

    public function testFractionWithinLimitPasses(): void
    {
        self::assertNull((new Digits(6, 2))->validate(9999.99, 'field'));
    }

    public function testFractionExceedsLimitFails(): void
    {
        self::assertSame(
            'fraction part must not exceed 2 digits',
            (new Digits(6, 2))->validate(9.999, 'field')
        );
    }

    public function testTrailingZerosNotCounted(): void
    {
        // "9.990" → fraction = "99" after rtrim → 2 digits
        self::assertNull((new Digits(6, 2))->validate('9.990', 'field'));
    }

    public function testZeroFractionDefaultPasses(): void
    {
        // fraction default = 0; value with no decimal passes
        self::assertNull((new Digits(4))->validate(1234, 'field'));
    }

    public function testFractionWhenDefaultZeroFails(): void
    {
        self::assertSame(
            'fraction part must not exceed 0 digits',
            (new Digits(4))->validate(12.5, 'field')
        );
    }

    // ── string input ──────────────────────────────────────────────────────────

    public function testNumericStringPasses(): void
    {
        self::assertNull((new Digits(6, 2))->validate('9999.99', 'field'));
    }

    public function testNonNumericStringSkipped(): void
    {
        self::assertNull((new Digits(6, 2))->validate('abc', 'field'));
    }

    // ── BcMath\Number ─────────────────────────────────────────────────────────

    public function testBcMathNumberPasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull((new Digits(6, 2))->validate(new \BcMath\Number('9999.99'), 'field'));
    }

    public function testBcMathNumberFractionFails(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertSame(
            'fraction part must not exceed 2 digits',
            (new Digits(6, 2))->validate(new \BcMath\Number('9.999'), 'field')
        );
    }
}
