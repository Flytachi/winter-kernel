<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\WebSocket;

use Flytachi\Winter\Kernel\Tests\App\TlsPair;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\TlsEchoSocket;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;

/**
 * `wss://` end to end: {@see TlsEchoSocket} in a separate PHP process on a self-signed
 * certificate, clients in coroutines here.
 */
#[Group('integration')]
final class WebSocketTlsServeTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private int $port = 0;
    private string $root = '';
    private ?TlsPair $pair = null;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole') || !defined('SWOOLE_SSL')) {
            self::markTestSkipped('wss needs ext-swoole built with OpenSSL.');
        }
        $this->pair = TlsPair::create() ?? self::markTestSkipped('needs ext-openssl to make a certificate.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            $status = proc_get_status($this->server);
            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
            }
            proc_close($this->server);
        }
        if ($this->root !== '') {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
        $this->pair?->remove();
    }

    public function test_a_tls_client_talks_and_the_handshake_sees_https(): void
    {
        $this->startServer();

        [$status, $reply] = self::co(function (): array {
            $c = $this->client(ssl: true);
            $c->upgrade('/');
            $c->push('hello');
            $frame = $c->recv(3);
            $c->close();
            return [$c->statusCode, $frame->data ?? null];
        });

        self::assertSame(101, $status);
        self::assertSame('https:hello', $reply);
        self::assertStringContainsString('WebSocket listening on wss://127.0.0.1:' . $this->port, $this->serverLog());
    }

    public function test_a_plain_client_gets_no_websocket(): void
    {
        $this->startServer();

        $upgraded = self::co(function (): bool {
            $c = $this->client(ssl: false);
            $ok = $c->upgrade('/');
            $c->close();
            return $ok && $c->statusCode === 101;
        });

        self::assertFalse($upgraded);
    }

    private function startServer(): void
    {
        $this->root = sys_get_temp_dir() . '/wk_wss_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->port = self::freePort();

        $script = $this->root . '/server.php';
        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\n"
            . "Flytachi\\Winter\\Kernel\\Kernel::init(pathRoot: %s);\n"
            . "Flytachi\\Winter\\DI\\Container::init();\n"
            . "%s::start(%d, '127.0.0.1');\n",
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->root, true),
            TlsEchoSocket::class,
            $this->port,
        ));

        $log = $this->root . '/stdout.log';
        $this->server = proc_open(
            [PHP_BINARY, $script],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            ['XDEBUG_MODE' => 'off', 'LOG_LEVEL' => 'info', 'LOG_OUTPUT' => 'stdout', 'LOG_COLOR' => 'never',
                'SERVER_TLS_CERT' => $this->pair->cert, 'SERVER_TLS_KEY' => $this->pair->key] + getenv(),
        );
        self::assertIsResource($this->server);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (str_contains($this->serverLog(), 'WebSocket listening')) {
                return;
            }
            if (!proc_get_status($this->server)['running']) {
                break;
            }
            usleep(50_000);
        }
        self::fail("server did not start:\n" . $this->serverLog());
    }

    private function serverLog(): string
    {
        return (string) @file_get_contents($this->root . '/stdout.log');
    }

    private function client(bool $ssl): Client
    {
        $c = new Client('127.0.0.1', $this->port, $ssl);
        $c->set(['timeout' => 3, 'ssl_verify_peer' => false]);
        return $c;
    }

    private static function co(callable $fn): mixed
    {
        $result = null;
        $error = null;
        \Swoole\Coroutine\run(static function () use ($fn, &$result, &$error): void {
            try {
                $result = $fn();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error !== null) {
            throw $error;
        }
        return $result;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
