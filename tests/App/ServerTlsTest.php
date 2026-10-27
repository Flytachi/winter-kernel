<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use PHPUnit\Framework\TestCase;

/**
 * HTTPS and HTTP/2 settings, and the checks that stop a boot whose TLS could not work.
 *
 * Before TLS was supported, `->set('ssl_cert_file', …)` left the socket plain: the
 * handshake failed and the server answered plain HTTP, saying nothing. A setup that
 * cannot work is now refused at boot, by name.
 */
final class ServerTlsTest extends TestCase
{
    private static ?TlsPair $pair = null;

    public static function setUpBeforeClass(): void
    {
        self::$pair = TlsPair::create();
    }

    public static function tearDownAfterClass(): void
    {
        self::$pair?->remove();
    }

    private function pair(): TlsPair
    {
        if (self::$pair === null) {
            self::markTestSkipped('needs ext-openssl to make a certificate.');
        }
        return self::$pair;
    }

    public function test_off_by_default(): void
    {
        $s = ServerSettings::fromEnv();

        self::assertFalse($s->isTls());
        self::assertNull($s->getTlsCert());
        self::assertFalse($s->isHttp2());
        $s->assertTls();
    }

    public function test_tls_in_code(): void
    {
        $pair = $this->pair();
        $s = ServerSettings::fromEnv()->tls($pair->cert, $pair->key);

        self::assertTrue($s->isTls());
        self::assertSame($pair->cert, $s->getTlsCert());
        self::assertSame([$pair->cert, $pair->key], [$s->toArray()['ssl_cert_file'], $s->toArray()['ssl_key_file']]);
        $s->assertTls();
    }

    public function test_tls_and_http2_from_the_environment(): void
    {
        $pair = $this->pair();
        $_ENV['SERVER_TLS_CERT'] = $pair->cert;
        $_ENV['SERVER_TLS_KEY'] = $pair->key;
        $_ENV['SERVER_HTTP2'] = 'true';
        try {
            $s = ServerSettings::fromEnv();
            self::assertTrue($s->isTls());
            self::assertTrue($s->isHttp2());
            self::assertTrue($s->toArray()['open_http2_protocol']);
        } finally {
            unset($_ENV['SERVER_TLS_CERT'], $_ENV['SERVER_TLS_KEY'], $_ENV['SERVER_HTTP2']);
        }
    }

    /** The code has the last word over `.env`, as everywhere in the server settings. */
    public function test_code_overrides_the_environment(): void
    {
        $_ENV['SERVER_HTTP2'] = 'true';
        try {
            self::assertFalse(ServerSettings::fromEnv()->http2(false)->isHttp2());
        } finally {
            unset($_ENV['SERVER_HTTP2']);
        }
    }

    public function test_http2_is_switched_on_on_its_own(): void
    {
        $s = ServerSettings::fromEnv()->http2();

        self::assertTrue($s->isHttp2());
        self::assertFalse($s->isTls(), 'HTTP/2 does not bring TLS with it');
    }

    public function test_a_certificate_without_its_key_is_refused(): void
    {
        $_ENV['SERVER_TLS_CERT'] = $this->pair()->cert;
        try {
            $this->expectException(ApplicationConfigException::class);
            $this->expectExceptionMessage('TLS needs both files');
            ServerSettings::fromEnv()->assertTls();
        } finally {
            unset($_ENV['SERVER_TLS_CERT']);
        }
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('The TLS certificate cannot be read: /nonexistent/app.crt');
        ServerSettings::fromEnv()->tls('/nonexistent/app.crt', $this->pair()->key)->assertTls();
    }

    public function test_a_file_that_is_no_certificate_is_refused(): void
    {
        $pair = $this->pair();
        file_put_contents($pair->dir . '/junk.pem', 'not a certificate');

        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('Not a PEM certificate');
        ServerSettings::fromEnv()->tls($pair->dir . '/junk.pem', $pair->key)->assertTls();
    }

    public function test_a_key_of_another_certificate_is_refused(): void
    {
        $pair = $this->pair();
        $pair->write('other.pem', 'other.key');

        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('The TLS key does not belong to the certificate');
        ServerSettings::fromEnv()->tls($pair->cert, $pair->dir . '/other.key')->assertTls();
    }

    /** Swoole puts no scheme in the request: the server says whether it speaks TLS. */
    public function test_a_request_to_a_tls_server_is_https(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }
        $raw = new \Swoole\Http\Request();
        $raw->server = ['server_port' => 8443, 'request_uri' => '/a'];
        $raw->header = ['host' => 'api.example.com:8443'];

        $request = new SwooleRequest($raw, tls: true);
        self::assertSame('https', $request->getScheme());
        self::assertSame('https://api.example.com:8443/a', $request->getUrl());
        self::assertSame('http', new SwooleRequest($raw)->getScheme());
    }

    /** A proxy saw the client's side: its header still wins. */
    public function test_a_proxy_header_wins_over_the_server(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }
        $raw = new \Swoole\Http\Request();
        $raw->server = ['server_port' => 8443];
        $raw->header = ['x-forwarded-proto' => 'http'];

        self::assertSame('http', new SwooleRequest($raw, tls: true)->getScheme());
    }
}
