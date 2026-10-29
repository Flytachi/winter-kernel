<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\BindAddress;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\Http\Adapter\ClientAddress;
use Flytachi\Winter\Kernel\Http\Adapter\FpmRequest;
use PHPUnit\Framework\TestCase;

/**
 * IPv4 and IPv6 bind addresses, and an IPv4 client seen through an IPv6 socket.
 */
final class BindAddressTest extends TestCase
{
    public function test_an_ipv6_host_is_told_apart(): void
    {
        self::assertTrue(BindAddress::isIpv6('::'));
        self::assertTrue(BindAddress::isIpv6('::1'));
        self::assertTrue(BindAddress::isIpv6('fd00::5'));
        self::assertFalse(BindAddress::isIpv6('0.0.0.0'));
        self::assertFalse(BindAddress::isIpv6('localhost'));
    }

    /** `http://:::8000` would run the host's colons into the port's. */
    public function test_an_ipv6_host_goes_in_brackets_in_a_url(): void
    {
        self::assertSame('http://[::]:8000', BindAddress::url('http', '::', 8000));
        self::assertSame('ws://[::1]:8001', BindAddress::url('ws', '::1', 8001));
        self::assertSame('http://0.0.0.0:8000', BindAddress::url('http', '0.0.0.0', 8000));
    }

    /** `[::1]` written as in a URL: Swoole refuses the brackets, and the URL had two pairs. */
    public function test_a_host_given_in_brackets_is_taken_bare(): void
    {
        self::assertSame('::1', BindAddress::bare('[::1]'));
        self::assertSame('::1', BindAddress::bare(' ::1 '));
        self::assertSame('0.0.0.0', BindAddress::bare('0.0.0.0'));
        self::assertSame('http://[::1]:8000', BindAddress::url('http', '[::1]', 8000));
        self::assertSame('::1', Component::http('[::1]')->host);
        self::assertSame('::', Component::http()->at('[::]', 8001)->host);
        self::assertSame('::1', ServerSettings::fromEnv()->host('[::1]')->getHost());
    }

    public function test_an_ipv4_mapped_address_is_unmapped(): void
    {
        self::assertSame('203.0.113.7', ClientAddress::unmapped('::ffff:203.0.113.7'));
        self::assertSame('127.0.0.1', ClientAddress::unmapped('::FFFF:127.0.0.1'));
    }

    public function test_other_addresses_are_left_as_they_are(): void
    {
        self::assertSame('::1', ClientAddress::unmapped('::1'));
        self::assertSame('203.0.113.7', ClientAddress::unmapped('203.0.113.7'));
        self::assertSame('::ffff:abcd', ClientAddress::unmapped('::ffff:abcd'));
        self::assertSame('2001:db8::1', ClientAddress::unmapped('2001:db8::1'));
    }

    /** A web server on a dual-stack socket hands FPM the mapped form as well. */
    public function test_fpm_reads_a_mapped_client_as_ipv4(): void
    {
        $original = $_SERVER;
        $_SERVER = ['REMOTE_ADDR' => '::ffff:10.0.0.7'];
        try {
            self::assertSame('10.0.0.7', new FpmRequest()->getClientIp());
        } finally {
            $_SERVER = $original;
        }
    }

    public function test_swoole_reads_a_mapped_client_as_ipv4(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }
        $raw = new \Swoole\Http\Request();
        $raw->server = ['remote_addr' => '::ffff:10.0.0.7'];
        $raw->header = [];

        self::assertSame('10.0.0.7', new \Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest($raw)->getClientIp());
    }
}
