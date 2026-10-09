<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Adapter\FpmRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `getProtocolVersion()` and `getUserAgent()` read the same thing under both runtimes.
 *
 * The raw values are the ones measured on live servers: Swoole 6.2 publishes
 * `server_protocol` as 'HTTP/1.0', 'HTTP/1.1' and 'HTTP/2' (h2c and over TLS alike); nginx
 * 1.31 hands FPM 'HTTP/2.0' for an HTTP/2 client and, for HTTP/3, 'HTTP/3.0'. Both
 * spellings of a version must read the same.
 */
final class ProtocolAndUserAgentTest extends TestCase
{
    /** @return array<string, array{?string, string}> */
    public static function protocols(): array
    {
        return [
            'HTTP/1.0'               => ['HTTP/1.0', '1.0'],
            'HTTP/1.1'               => ['HTTP/1.1', '1.1'],
            'HTTP/2 (Swoole)'        => ['HTTP/2', '2'],
            'HTTP/2.0 (nginx → FPM)' => ['HTTP/2.0', '2'],
            'HTTP/3.0 (nginx → FPM)' => ['HTTP/3.0', '3'],
            'lower case'             => ['http/1.1', '1.1'],
            'absent'                 => [null, '1.1'],
            'not a protocol'         => ['SPDY/3', '1.1'],
        ];
    }

    #[DataProvider('protocols')]
    public function test_swoole_protocol_version(?string $raw, string $expected): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }

        $request = new \Swoole\Http\Request();
        $request->server = $raw === null ? [] : ['server_protocol' => $raw];

        self::assertSame($expected, new SwooleRequest($request)->getProtocolVersion());
    }

    #[DataProvider('protocols')]
    public function test_fpm_protocol_version(?string $raw, string $expected): void
    {
        $original = $_SERVER;
        unset($_SERVER['SERVER_PROTOCOL']);
        if ($raw !== null) {
            $_SERVER['SERVER_PROTOCOL'] = $raw;
        }

        try {
            self::assertSame($expected, new FpmRequest()->getProtocolVersion());
        } finally {
            $_SERVER = $original;
        }
    }

    public function test_swoole_user_agent(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }

        $request = new \Swoole\Http\Request();
        $request->header = ['user-agent' => 'Kannel/1.4.5'];
        self::assertSame('Kannel/1.4.5', new SwooleRequest($request)->getUserAgent());

        $request->header = [];
        self::assertNull(new SwooleRequest($request)->getUserAgent(), 'no header, no agent');
    }

    public function test_fpm_user_agent(): void
    {
        $original = $_SERVER;
        try {
            $_SERVER['HTTP_USER_AGENT'] = 'Kannel/1.4.5';
            self::assertSame('Kannel/1.4.5', new FpmRequest()->getUserAgent());

            unset($_SERVER['HTTP_USER_AGENT']);
            self::assertNull(new FpmRequest()->getUserAgent(), 'no header, no agent');
        } finally {
            $_SERVER = $original;
        }
    }
}
