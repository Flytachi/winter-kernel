<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\NegativeOrZero;
use PHPUnit\Framework\TestCase;

class NegativeOrZeroTest extends TestCase
{
    private NegativeOrZero $constraint;

    protected function setUp(): void
    {
        $this->constraint = new NegativeOrZero();
    }

    public function testNullPasses(): void
    {
        self::assertNull($this->constraint->validate(null, 'field'));
    }

    public function testNegativeIntPasses(): void
    {
        self::assertNull($this->constraint->validate(-1, 'field'));
    }

    public function testZeroPasses(): void
    {
        self::assertNull($this->constraint->validate(0, 'field'));
    }

    public function testPositiveIntFails(): void
    {
        self::assertSame('must be negative or zero', $this->constraint->validate(1, 'field'));
    }

    public function testNegativeFloatPasses(): void
    {
        self::assertNull($this->constraint->validate(-0.01, 'field'));
    }

    public function testZeroFloatPasses(): void
    {
        self::assertNull($this->constraint->validate(0.0, 'field'));
    }

    public function testPositiveFloatFails(): void
    {
        self::assertSame('must be negative or zero', $this->constraint->validate(0.01, 'field'));
    }

    public function testBcMathNegativePasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull($this->constraint->validate(new \BcMath\Number('-5'), 'field'));
    }

    public function testBcMathZeroPasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull($this->constraint->validate(new \BcMath\Number('0'), 'field'));
    }

    public function testBcMathPositiveFails(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertSame('must be negative or zero', $this->constraint->validate(new \BcMath\Number('1'), 'field'));
    }

    public function testStringIsSkipped(): void
    {
        self::assertNull($this->constraint->validate('hello', 'field'));
    }
}
