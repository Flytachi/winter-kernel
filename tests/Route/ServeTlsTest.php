<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Tests\App\TlsPair;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\ServerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP server speaking TLS, with HTTP/2 switched on, started from `SERVER_TLS_CERT`,
 * `SERVER_TLS_KEY` and `SERVER_HTTP2` the way a container passes them.
 *
 * The certificate options used to leave the socket plain: the handshake failed and the
 * server answered `http://`. And Swoole puts no scheme in the request, so an application
 * reached over TLS read its own URLs as `http://`.
 */
#[Group('integration')]
final class ServeTlsTest extends TestCase
{
    private static ?ServerProcess $server = null;
    private static ?TlsPair $pair = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('swoole') || !defined('SWOOLE_SSL')) {
            self::markTestSkipped('needs Swoole built with OpenSSL.');
        }
        if (!function_exists('curl_init') || !(curl_version()['features'] & CURL_VERSION_HTTP2)) {
            self::markTestSkipped('needs ext-curl with HTTP/2.');
        }
        self::$pair = TlsPair::create() ?? self::markTestSkipped('needs ext-openssl.');

        self::$server = new ServerProcess(env: [
            'SERVER_TLS_CERT' => self::$pair->cert,
            'SERVER_TLS_KEY'  => self::$pair->key,
            'SERVER_HTTP2'    => 'true',
        ]);
        if (!self::$server->start()) {
            $log = self::$server->log();
            self::$server->stop();
            self::$server = null;
            self::fail("the TLS server did not come up:\n" . substr($log, 0, 600));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        self::$pair?->remove();
    }

    /** @return array{status: int, body: string, version: int} */
    private static function get(string $url, int $version): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTP_VERSION   => $version,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($curl);
        return [
            'status'  => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'body'    => is_string($body) ? $body : '',
            'version' => (int) curl_getinfo($curl, CURLINFO_HTTP_VERSION),
        ];
    }

    public function test_https_over_http_1_1(): void
    {
        $port = self::$server->port();
        $response = self::get("https://127.0.0.1:{$port}/demo/scheme", CURL_HTTP_VERSION_1_1);

        self::assertSame(200, $response['status']);
        self::assertSame(
            ['scheme' => 'https', 'url' => "https://127.0.0.1:{$port}/demo/scheme", 'protocol' => '1.1'],
            json_decode($response['body'], true),
        );
    }

    public function test_https_over_http_2(): void
    {
        $port = self::$server->port();
        $response = self::get("https://127.0.0.1:{$port}/demo/scheme", CURL_HTTP_VERSION_2TLS);

        self::assertSame(200, $response['status']);
        self::assertSame(CURL_HTTP_VERSION_2_0, $response['version']);
        self::assertSame('https', json_decode($response['body'], true)['scheme']);
        self::assertSame('2', json_decode($response['body'], true)['protocol']);
    }

    /** The port speaks TLS only: a plain request gets no answer rather than a wrong one. */
    public function test_plain_http_gets_no_answer(): void
    {
        $response = self::get('http://127.0.0.1:' . self::$server->port() . '/demo/scheme', CURL_HTTP_VERSION_1_1);

        self::assertSame(0, $response['status']);
    }
}
