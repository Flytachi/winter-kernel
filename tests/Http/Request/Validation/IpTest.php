<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Ip;
use Flytachi\Winter\K2\Http\Request\Validation\Ipv4;
use Flytachi\Winter\K2\Http\Request\Validation\Ipv6;
use PHPUnit\Framework\TestCase;

class IpTest extends TestCase
{
    // ── #[Ip] ─────────────────────────────────────────────────────────────────

    public function testIpNullPasses(): void
    {
        self::assertNull((new Ip())->validate(null, 'field'));
    }

    public function testIpv4Passes(): void
    {
        self::assertNull((new Ip())->validate('192.168.1.1', 'field'));
    }

    public function testIpv6Passes(): void
    {
        self::assertNull((new Ip())->validate('::1', 'field'));
    }

    public function testIpv6FullPasses(): void
    {
        self::assertNull((new Ip())->validate('2001:0db8:85a3:0000:0000:8a2e:0370:7334', 'field'));
    }

    public function testInvalidIpFails(): void
    {
        self::assertSame('must be a valid IP address', (new Ip())->validate('not-an-ip', 'field'));
    }

    public function testEmptyStringFails(): void
    {
        self::assertSame('must be a valid IP address', (new Ip())->validate('', 'field'));
    }

    // ── #[Ipv4] ───────────────────────────────────────────────────────────────

    public function testIpv4NullPasses(): void
    {
        self::assertNull((new Ipv4())->validate(null, 'field'));
    }

    public function testValidIpv4Passes(): void
    {
        self::assertNull((new Ipv4())->validate('192.168.1.1', 'field'));
    }

    public function testIpv6FailsForIpv4(): void
    {
        self::assertSame('must be a valid IPv4 address', (new Ipv4())->validate('::1', 'field'));
    }

    public function testInvalidIpv4Fails(): void
    {
        self::assertSame('must be a valid IPv4 address', (new Ipv4())->validate('999.999.999.999', 'field'));
    }

    public function testLoopbackIpv4Passes(): void
    {
        self::assertNull((new Ipv4())->validate('127.0.0.1', 'field'));
    }

    // ── #[Ipv6] ───────────────────────────────────────────────────────────────

    public function testIpv6NullPasses(): void
    {
        self::assertNull((new Ipv6())->validate(null, 'field'));
    }

    public function testValidIpv6Passes(): void
    {
        self::assertNull((new Ipv6())->validate('::1', 'field'));
    }

    public function testValidIpv6FullPasses(): void
    {
        self::assertNull((new Ipv6())->validate('2001:0db8:85a3:0000:0000:8a2e:0370:7334', 'field'));
    }

    public function testIpv4FailsForIpv6(): void
    {
        self::assertSame('must be a valid IPv6 address', (new Ipv6())->validate('192.168.1.1', 'field'));
    }

    public function testInvalidIpv6Fails(): void
    {
        self::assertSame('must be a valid IPv6 address', (new Ipv6())->validate('not-ipv6', 'field'));
    }
}
