<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request\Validation;

use Flytachi\Winter\Kernel\Http\Request\Validation\Url;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    private Url $constraint;

    protected function setUp(): void
    {
        $this->constraint = new Url();
    }

    public function testNullPasses(): void
    {
        self::assertNull($this->constraint->validate(null, 'url'));
    }

    public function testHttpsPasses(): void
    {
        self::assertNull($this->constraint->validate('https://example.com', 'url'));
    }

    public function testHttpPasses(): void
    {
        self::assertNull($this->constraint->validate('http://example.com', 'url'));
    }

    public function testFtpPasses(): void
    {
        self::assertNull($this->constraint->validate('ftp://files.example.com', 'url'));
    }

    public function testUrlWithPathAndQueryPasses(): void
    {
        self::assertNull($this->constraint->validate('https://example.com/path?q=1&page=2', 'url'));
    }

    public function testUrlWithPortPasses(): void
    {
        self::assertNull($this->constraint->validate('https://example.com:8080/api', 'url'));
    }

    public function testPlainStringFails(): void
    {
        self::assertSame('must be a valid URL', $this->constraint->validate('notaurl', 'url'));
    }

    public function testMissingSchemeFails(): void
    {
        self::assertSame('must be a valid URL', $this->constraint->validate('example.com', 'url'));
    }

    public function testEmptyStringFails(): void
    {
        self::assertSame('must be a valid URL', $this->constraint->validate('', 'url'));
    }

    public function testSpaceInUrlFails(): void
    {
        self::assertSame('must be a valid URL', $this->constraint->validate('https://exam ple.com', 'url'));
    }
}
