<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Regex;
use PHPUnit\Framework\TestCase;

class RegexTest extends TestCase
{
    public function testNullPasses(): void
    {
        self::assertNull((new Regex('/^\d+$/'))->validate(null, 'field'));
    }

    public function testMatchingValuePasses(): void
    {
        self::assertNull((new Regex('/^\d{4}$/'))->validate('2024', 'field'));
    }

    public function testNonMatchingValueFails(): void
    {
        self::assertSame(
            'must match pattern /^\d{4}$/',
            (new Regex('/^\d{4}$/'))->validate('abc', 'field')
        );
    }

    public function testCustomMessageOnFail(): void
    {
        self::assertSame(
            'only lowercase letters allowed',
            (new Regex('/^[a-z]+$/', 'only lowercase letters allowed'))->validate('ABC', 'field')
        );
    }

    public function testCustomMessageNotShownOnPass(): void
    {
        self::assertNull(
            (new Regex('/^[a-z]+$/', 'only lowercase letters allowed'))->validate('abc', 'field')
        );
    }

    public function testPhonePatternPasses(): void
    {
        self::assertNull((new Regex('/^\+?\d{7,15}$/'))->validate('+79001234567', 'phone'));
    }

    public function testPhonePatternFails(): void
    {
        self::assertSame(
            'must match pattern /^\+?\d{7,15}$/',
            (new Regex('/^\+?\d{7,15}$/'))->validate('not-a-phone', 'phone')
        );
    }

    public function testEmptyStringAgainstNonEmptyPattern(): void
    {
        self::assertSame(
            'must match pattern /^\d+$/',
            (new Regex('/^\d+$/'))->validate('', 'field')
        );
    }

    public function testIntValueCastToString(): void
    {
        self::assertNull((new Regex('/^\d+$/'))->validate(123, 'field'));
    }
}
