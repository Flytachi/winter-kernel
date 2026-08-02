<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request\Validation;

use Flytachi\Winter\Kernel\Http\Request\Validation\Email;
use PHPUnit\Framework\TestCase;

class EmailTest extends TestCase
{
    private Email $constraint;

    protected function setUp(): void
    {
        $this->constraint = new Email();
    }

    public function testNullPasses(): void
    {
        self::assertNull($this->constraint->validate(null, 'email'));
    }

    public function testValidEmailPasses(): void
    {
        self::assertNull($this->constraint->validate('user@mail.com', 'email'));
    }

    public function testValidEmailWithSubdomainPasses(): void
    {
        self::assertNull($this->constraint->validate('user@sub.mail.com', 'email'));
    }

    public function testValidEmailWithPlusPasses(): void
    {
        self::assertNull($this->constraint->validate('user+tag@mail.com', 'email'));
    }

    public function testNoAtSignFails(): void
    {
        self::assertSame('must be a valid email address', $this->constraint->validate('notanemail', 'email'));
    }

    public function testMissingDomainFails(): void
    {
        self::assertSame('must be a valid email address', $this->constraint->validate('user@', 'email'));
    }

    public function testMissingLocalPartFails(): void
    {
        self::assertSame('must be a valid email address', $this->constraint->validate('@mail.com', 'email'));
    }

    public function testEmptyStringFails(): void
    {
        self::assertSame('must be a valid email address', $this->constraint->validate('', 'email'));
    }

    public function testSpacesInEmailFails(): void
    {
        self::assertSame('must be a valid email address', $this->constraint->validate('user @mail.com', 'email'));
    }
}
