<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use PHPUnit\Framework\TestCase;

class NotBlankTest extends TestCase
{
    private NotBlank $constraint;

    protected function setUp(): void
    {
        $this->constraint = new NotBlank();
    }

    // ── null passthrough ──────────────────────────────────────────────────────

    public function testNullPasses(): void
    {
        self::assertNull($this->constraint->validate(null, 'field'));
    }

    // ── blank strings ─────────────────────────────────────────────────────────

    public function testEmptyStringFails(): void
    {
        self::assertSame('must not be blank', $this->constraint->validate('', 'field'));
    }

    public function testWhitespaceOnlyFails(): void
    {
        self::assertSame('must not be blank', $this->constraint->validate('   ', 'field'));
    }

    public function testTabOnlyFails(): void
    {
        self::assertSame('must not be blank', $this->constraint->validate("\t\n", 'field'));
    }

    // ── valid strings ─────────────────────────────────────────────────────────

    public function testNonEmptyStringPasses(): void
    {
        self::assertNull($this->constraint->validate('hello', 'field'));
    }

    public function testStringWithLeadingSpacesPasses(): void
    {
        self::assertNull($this->constraint->validate('  hello  ', 'field'));
    }

    public function testSingleCharPasses(): void
    {
        self::assertNull($this->constraint->validate('a', 'field'));
    }
}
