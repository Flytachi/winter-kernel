<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request\Validation;

use Flytachi\Winter\Kernel\Http\Request\Validation\Negative;
use PHPUnit\Framework\TestCase;

class NegativeTest extends TestCase
{
    private Negative $constraint;

    protected function setUp(): void
    {
        $this->constraint = new Negative();
    }

    public function testNullPasses(): void
    {
        self::assertNull($this->constraint->validate(null, 'field'));
    }

    public function testNegativeIntPasses(): void
    {
        self::assertNull($this->constraint->validate(-1, 'field'));
    }

    public function testZeroFails(): void
    {
        self::assertSame('must be negative', $this->constraint->validate(0, 'field'));
    }

    public function testPositiveIntFails(): void
    {
        self::assertSame('must be negative', $this->constraint->validate(1, 'field'));
    }

    public function testNegativeFloatPasses(): void
    {
        self::assertNull($this->constraint->validate(-0.01, 'field'));
    }

    public function testZeroFloatFails(): void
    {
        self::assertSame('must be negative', $this->constraint->validate(0.0, 'field'));
    }

    public function testBcMathNegativePasses(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertNull($this->constraint->validate(new \BcMath\Number('-5'), 'field'));
    }

    public function testBcMathZeroFails(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath not loaded');
        }
        self::assertSame('must be negative', $this->constraint->validate(new \BcMath\Number('0'), 'field'));
    }

    public function testStringIsSkipped(): void
    {
        self::assertNull($this->constraint->validate('hello', 'field'));
    }
}
