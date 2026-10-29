<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\WebSocket;

use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Tests\App\TlsPair;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\OwnCertSocket;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\TlsEchoSocket;
use Flytachi\Winter\Kernel\WebSocket\WebSocketConfigException;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;

/**
 * `wss://` without a server: which certificate a class serves with, and the setups
 * refused before anything binds. The handshake itself is in {@see WebSocketTlsServeTest}.
 */
final class WebSocketTlsTest extends TestCase
{
    private static ?TlsPair $pair = null;
    private string $root = '';

    public static function setUpBeforeClass(): void
    {
        self::$pair = TlsPair::create();
    }

    public static function tearDownAfterClass(): void
    {
        self::$pair?->remove();
    }

    protected function setUp(): void
    {
        $this->root = Kernel::$pathRoot ?? '';
    }

    protected function tearDown(): void
    {
        unset($_ENV['SERVER_TLS_CERT'], $_ENV['SERVER_TLS_KEY'], $_ENV['CHAT_TLS_CERT'], $_ENV['CHAT_TLS_KEY']);
        Kernel::$pathRoot = $this->root;
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
        $_ENV['SERVER_TLS_CERT'] = '/nowhere/cert.pem';

        self::assertFalse(EchoSocket::speaksTls());
        self::assertNull(EchoSocket::tlsPair(), 'the HTTP certificate alone does not turn it on');
    }

    public function test_takes_the_http_certificate(): void
    {
        $pair = $this->pair();
        $_ENV['SERVER_TLS_CERT'] = $pair->cert;
        $_ENV['SERVER_TLS_KEY'] = $pair->key;

        self::assertTrue(TlsEchoSocket::speaksTls());
        self::assertSame([$pair->cert, $pair->key], TlsEchoSocket::tlsPair());
    }

    public function test_relative_paths_are_from_the_project_root(): void
    {
        $pair = $this->pair();
        Kernel::$pathRoot = $pair->dir;
        $_ENV['SERVER_TLS_CERT'] = 'cert.pem';
        $_ENV['SERVER_TLS_KEY'] = 'key.pem';

        self::assertSame([$pair->dir . '/cert.pem', $pair->dir . '/key.pem'], TlsEchoSocket::tlsPair());
    }

    public function test_tls_files_names_its_own_certificate(): void
    {
        $pair = $this->pair();
        $pair->write('chat.pem', 'chat.key');
        $_ENV['CHAT_TLS_CERT'] = $pair->dir . '/chat.pem';
        $_ENV['CHAT_TLS_KEY'] = $pair->dir . '/chat.key';

        self::assertSame([$pair->dir . '/chat.pem', $pair->dir . '/chat.key'], OwnCertSocket::tlsPair());
    }

    public function test_no_certificate_is_refused(): void
    {
        $this->expectException(WebSocketConfigException::class);
        $this->expectExceptionMessage('has $tls on, but no certificate');

        TlsEchoSocket::tlsPair();
    }

    public function test_half_a_pair_is_refused(): void
    {
        $_ENV['SERVER_TLS_CERT'] = $this->pair()->cert;

        $this->expectException(WebSocketConfigException::class);
        $this->expectExceptionMessage('only half of the certificate pair');

        TlsEchoSocket::tlsPair();
    }

    public function test_a_key_of_another_certificate_is_refused(): void
    {
        $pair = $this->pair();
        $pair->write('other.pem', 'other.key');
        $_ENV['SERVER_TLS_CERT'] = $pair->cert;
        $_ENV['SERVER_TLS_KEY'] = $pair->dir . '/other.key';

        $this->expectException(WebSocketConfigException::class);
        $this->expectExceptionMessage(TlsEchoSocket::class . ': The TLS key does not belong to the certificate');

        TlsEchoSocket::tlsPair();
    }

    public function test_an_unreadable_file_is_refused(): void
    {
        $_ENV['SERVER_TLS_CERT'] = '/nowhere/cert.pem';
        $_ENV['SERVER_TLS_KEY'] = '/nowhere/key.pem';

        $this->expectException(WebSocketConfigException::class);
        $this->expectExceptionMessage('The TLS certificate cannot be read: /nowhere/cert.pem');

        TlsEchoSocket::tlsPair();
    }

    /** The application stops at boot, naming the class, before any component starts. */
    public function test_the_application_checks_it_at_boot(): void
    {
        $check = new \ReflectionMethod(WinterApplication::class, 'assertWebSocketTls');

        $check->invoke(null, [Component::websocket(EchoSocket::class, 8001)]);
        try {
            $check->invoke(null, [Component::websocket(TlsEchoSocket::class, 8001)]);
            self::fail('a wss socket without a certificate passed the boot');
        } catch (ApplicationConfigException $e) {
            self::assertStringContainsString(TlsEchoSocket::class . ' has $tls on, but no certificate', $e->getMessage());
        }
    }
}
