<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Required;
use PHPUnit\Framework\TestCase;

class RequiredTest extends TestCase
{
    private Required $constraint;

    protected function setUp(): void
    {
        $this->constraint = new Required();
    }

    public function testNullFails(): void
    {
        self::assertSame('is required', $this->constraint->validate(null, 'field'));
    }

    public function testZeroIntPasses(): void
    {
        self::assertNull($this->constraint->validate(0, 'field'));
    }

    public function testEmptyStringPasses(): void
    {
        // Required only checks for null — use #[NotBlank] to also reject empty strings
        self::assertNull($this->constraint->validate('', 'field'));
    }

    public function testFalsePasses(): void
    {
        self::assertNull($this->constraint->validate(false, 'field'));
    }

    public function testZeroFloatPasses(): void
    {
        self::assertNull($this->constraint->validate(0.0, 'field'));
    }

    public function testStringValuePasses(): void
    {
        self::assertNull($this->constraint->validate('hello', 'field'));
    }

    public function testIntValuePasses(): void
    {
        self::assertNull($this->constraint->validate(42, 'field'));
    }

    public function testArrayValuePasses(): void
    {
        self::assertNull($this->constraint->validate([], 'field'));
    }

    public function testObjectValuePasses(): void
    {
        self::assertNull($this->constraint->validate(new \stdClass(), 'field'));
    }

    public function testErrorMessageDoesNotIncludeFieldName(): void
    {
        // message is field-agnostic — the field name is the error key, not in the message
        $msg = $this->constraint->validate(null, 'myField');
        self::assertSame('is required', $msg);
        self::assertStringNotContainsString('myField', $msg);
    }
}
