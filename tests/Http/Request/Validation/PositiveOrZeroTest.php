<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request\Validation;

use Flytachi\Winter\Kernel\Http\Request\Validation\PositiveOrZero;
use PHPUnit\Framework\TestCase;

class PositiveOrZeroTest extends TestCase
{
    private PositiveOrZero $constraint;

    protected function setUp(): void
    {
        $this->constraint = new PositiveOrZero();
    }

    public function testNullPasses(): void
    {
        self::assertNull($this->constraint->validate(null, 'field'));
    }

    public function testPositiveIntPasses(): void
    {
        self::assertNull($this->constraint->validate(5, 'field'));
    }

    public function testZeroIntPasses(): void
    {
        self::assertNull($this->constraint->validate(0, 'field'));
    }

    public function testNegativeIntFails(): void
    {
        self::assertSame('must be positive or zero', $this->constraint->validate(-1, 'field'));
    }

    public function testPositiveFloatPasses(): void
    {
        self::assertNull($this->constraint->validate(0.01, 'field'));
    }

    public function testZeroFloatPasses(): void
    {
        self::assertNull($this->constraint->validate(0.0, 'field'));
    }

    public function testNegativeFloatFails(): void
    {
        self::assertSame('must be positive or zero', $this->constraint->validate(-0.01, 'field'));
    }

    public function testBcMathZeroPasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull($this->constraint->validate(new \BcMath\Number('0'), 'field'));
    }

    public function testBcMathNegativeFails(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertSame('must be positive or zero', $this->constraint->validate(new \BcMath\Number('-1'), 'field'));
    }

    public function testStringIsSkipped(): void
    {
        self::assertNull($this->constraint->validate('hello', 'field'));
    }
}
