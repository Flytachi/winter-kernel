<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request\Validation;

use Flytachi\Winter\Kernel\Http\Request\Validation\Assert;
use PHPUnit\Framework\TestCase;

function assert_must_be_even(mixed $value, string $field): ?string
{
    if ($value === null) {
        return null;
    }
    return is_int($value) && $value % 2 === 0 ? null : 'must be even';
}

class AssertCallableHelper
{
    public static function mustBePositive(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_numeric($value) && $value > 0 ? null : 'must be positive';
    }
}

class AssertTest extends TestCase
{
    public function testGlobalFunctionPasses(): void
    {
        $constraint = new Assert(__NAMESPACE__ . '\assert_must_be_even');
        self::assertNull($constraint->validate(4, 'field'));
    }

    public function testGlobalFunctionFails(): void
    {
        $constraint = new Assert(__NAMESPACE__ . '\assert_must_be_even');
        self::assertSame('must be even', $constraint->validate(3, 'field'));
    }

    public function testStaticMethodPasses(): void
    {
        $constraint = new Assert(AssertCallableHelper::class . '::mustBePositive');
        self::assertNull($constraint->validate(5, 'field'));
    }

    public function testStaticMethodFails(): void
    {
        $constraint = new Assert(AssertCallableHelper::class . '::mustBePositive');
        self::assertSame('must be positive', $constraint->validate(-1, 'field'));
    }

    public function testNullPassedToCallable(): void
    {
        $constraint = new Assert(__NAMESPACE__ . '\assert_must_be_even');
        self::assertNull($constraint->validate(null, 'field'));
    }

    public function testInvalidCallableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Assert('NonExistent::method'))->validate('x', 'field');
    }

    public function testIsRepeatable(): void
    {
        $a = new Assert(__NAMESPACE__ . '\assert_must_be_even');
        $b = new Assert(AssertCallableHelper::class . '::mustBePositive');

        // even + positive → 4 passes both
        self::assertNull($a->validate(4, 'field'));
        self::assertNull($b->validate(4, 'field'));

        // odd + positive → fails first
        self::assertSame('must be even', $a->validate(3, 'field'));
    }
}
