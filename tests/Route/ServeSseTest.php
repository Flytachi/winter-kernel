<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Tests\Route\Fixtures\ServerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Server-Sent Events over real HTTP: the stream on the wire, events pushed from another
 * request, the request deadline that does not apply, and a stop with streams open.
 */
#[Group('integration')]
final class ServeSseTest extends TestCase
{
    private ?ServerProcess $server = null;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('serving needs the Swoole extension.');
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /** @param array<string, string> $env */
    private function start(array $env = []): void
    {
        $this->server = new ServerProcess(env: ['LOG_LEVEL' => 'info', ...$env]);
        self::assertTrue($this->server->start(), (string) $this->server?->log());
    }

    /**
     * Opens a stream and reads it for $seconds; the socket is returned to keep it open.
     *
     * @param array<string, string> $headers
     * @return array{head: string, body: string, socket: resource}
     */
    private function open(string $path, float $seconds, array $headers = []): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->server?->port(), $errno, $error, 3.0);
        self::assertNotFalse($socket, $error);
        $head = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        fwrite($socket, $head . "\r\n");
        $raw = self::read($socket, $seconds);
        [$top, $body] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        return ['head' => $top, 'body' => $body, 'socket' => $socket];
    }

    /** @param resource $socket */
    private static function read($socket, float $seconds): string
    {
        stream_set_blocking($socket, false);
        $raw = '';
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $chunk = fread($socket, 65536);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                break;
            }
            $raw .= $chunk;
            usleep(20_000);
        }
        return $raw;
    }

    private function get(string $path): array
    {
        return (array) json_decode($this->server?->request('GET', $path)['body'] ?? '', true);
    }

    public function test_a_stream_goes_on_the_wire_as_event_stream(): void
    {
        $this->start();
        $stream = $this->open('/sse/clock', 0.7, ['Accept-Encoding' => 'gzip, br']);

        self::assertMatchesRegularExpression('#^HTTP/1\.1 200#', $stream['head']);
        self::assertStringContainsStringIgnoringCase('content-type: text/event-stream; charset=utf-8', $stream['head']);
        self::assertStringContainsStringIgnoringCase('x-accel-buffering: no', $stream['head']);
        self::assertStringNotContainsStringIgnoringCase('content-encoding', $stream['head'], 'never compressed');
        self::assertStringContainsString("retry: 3000\n: connected\n\n", $stream['body']);
        self::assertStringContainsString("event: tick\nid: 1\ndata: tick 1\n\n", $stream['body']);
        self::assertStringContainsString("event: tick\nid: 2\ndata: tick 2\n\n", $stream['body']);
        fclose($stream['socket']);
    }

    public function test_an_event_pushed_from_another_request_arrives(): void
    {
        $this->start();
        $stream = $this->open('/sse/board', 0.3);

        self::assertSame(1, $this->get('/sse/listeners')['listeners'] ?? null);
        $this->server?->request('POST', '/sse/board?text=hello%20all');
        $more = self::read($stream['socket'], 0.4);
        self::assertStringContainsString("event: message\ndata: hello all\n\n", $more);
        self::assertStringContainsString(": ping\n\n", $stream['body'] . $more, 'an idle stream keeps its heartbeat');
        fclose($stream['socket']);
    }

    public function test_a_client_gone_runs_on_close(): void
    {
        $this->start();
        $stream = $this->open('/sse/board', 0.3);
        self::assertSame(1, $this->get('/sse/listeners')['listeners'] ?? null);

        fclose($stream['socket']);
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline && ($this->get('/sse/listeners')['listeners'] ?? null) !== 0) {
            usleep(100_000);
        }
        self::assertSame(0, $this->get('/sse/listeners')['listeners'] ?? null, 'noticed at the next heartbeat');
    }

    public function test_a_stream_outlives_the_request_deadline(): void
    {
        $this->start(['SERVER_REQUEST_TIMEOUT' => '1']);
        $stream = $this->open('/sse/clock', 2.5);

        self::assertStringContainsString("id: 10\n", $stream['body'], 'still ticking after 2.5 s with a 1 s deadline');
        self::assertStringNotContainsString('504', $stream['head']);
        fclose($stream['socket']);
    }

    public function test_a_timeout_of_its_route_caps_a_stream(): void
    {
        $this->start();
        $started = microtime(true);
        // Without keep-alive the connection closes once the response has ended.
        $stream = $this->open('/sse/capped', 5.0, ['Connection' => 'close']);

        self::assertLessThan(4.0, microtime(true) - $started, 'ended by its #[Timeout(2)]');
        self::assertStringEndsWith("\r\n0\r\n\r\n", $stream['body'], 'the response ended, not cut');
        self::assertStringContainsString(": connected\n\n", $stream['body']);
        fclose($stream['socket']);
    }

    public function test_a_stop_with_streams_open_is_quick_and_clean(): void
    {
        $this->start();
        $streams = [$this->open('/sse/board', 0.3), $this->open('/sse/clock', 0.3)];

        $started = microtime(true);
        $this->server?->signal(SIGTERM);
        self::assertTrue($this->server?->awaitExit(10.0));
        $took = microtime(true) - $started;
        self::assertLessThan(2.0, $took, "stopped in {$took} s\n" . $this->server?->log());
        self::assertStringNotContainsString('exit timeout', (string) $this->server?->log());
        self::assertStringNotContainsString('deadlock', (string) $this->server?->log());
        foreach ($streams as $stream) {
            fclose($stream['socket']);
        }
    }

    public function test_a_stop_does_not_wait_for_a_client_that_stopped_reading(): void
    {
        $this->start();
        // A client on a dead network: connected, reading nothing; its socket fills up.
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->server?->port(), $errno, $error, 3.0);
        self::assertNotFalse($socket, $error);
        stream_set_chunk_size($socket, 1024);
        fwrite($socket, "GET /sse/flood HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        sleep(2);

        $started = microtime(true);
        $this->server?->signal(SIGTERM);
        self::assertTrue($this->server?->awaitExit(30.0));
        $took = microtime(true) - $started;
        fclose($socket);
        self::assertLessThan(3.0, $took, "stopped in {$took} s: the connection is cut, not drained\n");
    }

    public function test_head_gets_the_headers_only(): void
    {
        $this->start();
        $head = $this->server?->request('HEAD', '/sse/board');

        self::assertSame(200, $head['status'] ?? null);
        self::assertSame('', $head['body'] ?? null);
        self::assertSame(0, $this->get('/sse/listeners')['listeners'] ?? null, 'no stream was kept for it');
    }
}
