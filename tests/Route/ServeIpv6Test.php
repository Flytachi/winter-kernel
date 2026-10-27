<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Tests\Route\Fixtures\ServerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP server bound to `::` — every interface over both protocols, what an IPv6-only
 * cluster asks an application to listen on.
 *
 * Swoole binds IPv4 unless told otherwise and used to refuse `::` at start with "Invalid
 * IPv4 address". On an IPv6 socket it takes IPv4 clients too, seeing each as
 * `::ffff:127.0.0.1`; the application reads them as the IPv4 address they are.
 */
#[Group('integration')]
final class ServeIpv6Test extends TestCase
{
    private static ?ServerProcess $server = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('serving needs the Swoole extension.');
        }
        $probe = @stream_socket_server('tcp://[::1]:0');
        if ($probe === false) {
            self::markTestSkipped('this machine has no IPv6 loopback.');
        }
        fclose($probe);

        self::$server = new ServerProcess('::');
        if (!self::$server->start()) {
            $log = self::$server->log();
            self::$server->stop();
            self::$server = null;
            self::fail("the server on :: did not come up:\n" . substr($log, 0, 600));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    public function test_an_ipv6_client_is_served(): void
    {
        $response = self::$server->request('GET', '/demo/ip', '[::1]');

        self::assertSame(200, $response['status']);
        self::assertSame(['ip' => '::1'], json_decode($response['body'], true));
    }

    /** Not `::ffff:127.0.0.1`: a log line or an allow-list holding the IPv4 address must match. */
    public function test_an_ipv4_client_is_served_and_read_as_ipv4(): void
    {
        $response = self::$server->request('GET', '/demo/ip', '127.0.0.1');

        self::assertSame(200, $response['status']);
        self::assertSame(['ip' => '127.0.0.1'], json_decode($response['body'], true));
    }
}
