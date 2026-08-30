<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Response;

use PHPUnit\Framework\TestCase;

/**
 * What a HEAD announces, measured through both runtimes rather than assumed.
 *
 * There is no other way to see it. The adapters write `Content-Length` through an API
 * that answers `true` either way, so a spy holding the headers it was handed itself is
 * green even when the client receives none of them: with the client's `Accept-Encoding`
 * in hand — every browser sends one — Swoole compresses the body itself and throws the
 * `Content-Length` away, warning `ERRNO 7105`. The HEAD answer then carried no length at
 * all under Swoole while the same handler under FPM announced one, which is exactly the
 * kind of split the adapters exist to prevent.
 *
 * The servers run in child processes: `Swoole\Http\Server::start()` never returns, and
 * under CLI `header()` does nothing at all — the FPM half needs a real SAPI, and the
 * built-in server is one that ships with PHP.
 */
final class HeadContentLengthE2ETest extends TestCase
{
    /** Body the handler would send to a GET; comfortably over Swoole's compression floor. */
    private const BODY_SIZE = 4096;

    private string $dir = '';

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_head_len_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            // SIGTERM, not SIGKILL: Swoole's master forks a manager and its workers, and
            // only a signal it can handle takes them down with it.
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * The regression itself: the length survives a client that accepts compression, and
     * the runtime has nothing to complain about while it does.
     */
    public function test_head_announces_the_body_length_under_swoole(): void
    {
        $headers = $this->request($this->swoole(), 'HEAD');

        self::assertSame((string) self::BODY_SIZE, $headers['content-length'] ?? null);
        self::assertSame('identity', $headers['content-encoding'] ?? null);
        self::assertStringNotContainsString(
            '7105',
            (string) @file_get_contents($this->dir . '/server.log'),
            'Swoole warns and drops the header when it is left free to compress',
        );
    }

    /**
     * The other half of the bargain. `identity` is written on a HEAD, where there is no
     * body to encode — a GET must still be compressed, or the fix would have bought the
     * length back by sending every response uncompressed.
     */
    public function test_a_get_body_is_still_compressed_under_swoole(): void
    {
        $headers = $this->request($this->swoole(), 'GET');

        self::assertContains($headers['content-encoding'] ?? null, ['br', 'gzip']);
        self::assertLessThan(
            self::BODY_SIZE,
            (int) ($headers['content-length'] ?? PHP_INT_MAX),
            'a compressed body is shorter than the one the handler wrote',
        );
    }

    /** The same handler, the other runtime: same two headers. */
    public function test_head_announces_the_body_length_under_fpm(): void
    {
        $headers = $this->request($this->fpm(), 'HEAD');

        self::assertSame((string) self::BODY_SIZE, $headers['content-length'] ?? null);
        self::assertSame('identity', $headers['content-encoding'] ?? null);
    }

    /**
     * Starts a Swoole server whose handler answers through the adapter under test.
     *
     * @return string URL to request.
     */
    private function swoole(): string
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $port     = $this->freePort();
        $script   = $this->dir . '/server.php';

        file_put_contents($script, <<<PHP
            <?php
            require '{$autoload}';

            use Flytachi\\Winter\\Kernel\\Http\\Adapter\\SwooleResponse;

            \$server = new \\Swoole\\Http\\Server('127.0.0.1', {$port});
            // One worker is the harness. The log level is part of the test: warnings must
            // reach the log file, since the absence of one is what is being asserted.
            \$server->set(['worker_num' => 1, 'log_level' => SWOOLE_LOG_WARNING]);
            \$server->on('request', function (\\Swoole\\Http\\Request \$req, \\Swoole\\Http\\Response \$res) {
                \$head = strtoupper((string) \$req->server['request_method']) === 'HEAD';
                \$response = new SwooleResponse(\$res, \$head);
                \$response->header('Content-Type', 'text/plain');
                \$response->end(str_repeat('a', {$this->bodySize()}));
            });
            \$server->start();
            PHP);

        // The default ini is kept — `-n` would drop the Swoole extension with it. Xdebug is
        // switched off explicitly: its function observers do not survive Swoole's coroutine
        // stacks and the child segfaults at shutdown.
        $this->start([PHP_BINARY, '-d', 'xdebug.mode=off', $script], $this->dir . '/server.log');

        return "http://127.0.0.1:{$port}/";
    }

    /**
     * Starts the built-in server over the same handler, written against FpmResponse.
     *
     * @return string URL to request.
     */
    private function fpm(): string
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        file_put_contents($this->dir . '/index.php', <<<PHP
            <?php
            require '{$autoload}';

            use Flytachi\\Winter\\Kernel\\Http\\Adapter\\FpmResponse;

            \$head = strtoupper((string) \$_SERVER['REQUEST_METHOD']) === 'HEAD';
            \$response = new FpmResponse(\$head);
            \$response->header('Content-Type', 'text/plain');
            \$response->end(str_repeat('a', {$this->bodySize()}));
            PHP);

        $port = $this->freePort();
        $this->start([PHP_BINARY, '-n', '-S', "127.0.0.1:{$port}", '-t', $this->dir], $this->dir . '/server.log');

        return "http://127.0.0.1:{$port}/index.php";
    }

    /** @param list<string> $command */
    private function start(array $command, string $log): void
    {
        $this->server = proc_open(
            $command,
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
        );

        if (!is_resource($this->server)) {
            self::markTestSkipped('Could not start the server.');
        }
    }

    /**
     * Asks for the resource the way a browser would — announcing compression support —
     * and returns the response headers, names lower-cased.
     *
     * @return array<string, string>
     */
    private function request(string $url, string $method): array
    {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => "Accept-Encoding: gzip, br\r\n",
            'ignore_errors' => true,
            'timeout'       => 5,
        ]]);

        $raw = false;
        // The server needs a moment to bind; retry rather than sleep a fixed amount.
        for ($attempt = 0; $attempt < 50 && $raw === false; $attempt++) {
            $raw = @get_headers($url, false, $context);
            if ($raw === false) {
                usleep(100_000);
            }
        }

        if ($raw === false) {
            self::markTestSkipped('The server did not come up.');
        }

        $headers = [];
        foreach ($raw as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
            }
        }

        return $headers;
    }

    private function bodySize(): int
    {
        return self::BODY_SIZE;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped('Could not reserve a port.');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
