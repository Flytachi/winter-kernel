<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use PHPUnit\Framework\TestCase;

class PositiveTest extends TestCase
{
    private Positive $constraint;

    protected function setUp(): void
    {
        $this->constraint = new Positive();
    }

    public function testNullPasses(): void
    {
        self::assertNull($this->constraint->validate(null, 'field'));
    }

    public function testPositiveIntPasses(): void
    {
        self::assertNull($this->constraint->validate(1, 'field'));
    }

    public function testLargePositiveIntPasses(): void
    {
        self::assertNull($this->constraint->validate(999, 'field'));
    }

    public function testZeroIntFails(): void
    {
        self::assertSame('must be positive', $this->constraint->validate(0, 'field'));
    }

    public function testNegativeIntFails(): void
    {
        self::assertSame('must be positive', $this->constraint->validate(-1, 'field'));
    }

    public function testPositiveFloatPasses(): void
    {
        self::assertNull($this->constraint->validate(0.01, 'field'));
    }

    public function testZeroFloatFails(): void
    {
        self::assertSame('must be positive', $this->constraint->validate(0.0, 'field'));
    }

    public function testNegativeFloatFails(): void
    {
        self::assertSame('must be positive', $this->constraint->validate(-0.5, 'field'));
    }

    public function testBcMathPositivePasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull($this->constraint->validate(new \BcMath\Number('5'), 'field'));
    }

    public function testBcMathZeroFails(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertSame('must be positive', $this->constraint->validate(new \BcMath\Number('0'), 'field'));
    }

    public function testStringIsSkipped(): void
    {
        self::assertNull($this->constraint->validate('hello', 'field'));
    }
}
