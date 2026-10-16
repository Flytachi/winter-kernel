<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\WebSocket;

use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket;
use Flytachi\Winter\Kernel\WebSocket\WebSocketConfigException;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\CloseFrame;

/**
 * The WebSocket stereotype end to end: a real server in a separate PHP process
 * ({@see EchoSocket}), real clients in coroutines here.
 */
final class WebSocketTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private int $port = 0;
    private string $log = '';
    private string $root = '';

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('WebSocket needs ext-swoole.');
        }
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
    }

    // ── Handshake ─────────────────────────────────────────────────────────────

    public function test_handshake_answers_with_the_status_the_hook_chose(): void
    {
        $this->startServer();

        $statuses = self::co(function (): array {
            $out = [];
            foreach (['ok' => '/ws?token=ok', 'bad' => '/ws?token=bad', 'expired' => '/ws?token=expired',
                         'boom' => '/ws?token=boom', 'path' => '/other?token=ok'] as $case => $uri) {
                $c = $this->client();
                $c->upgrade($uri);
                $out[$case] = $c->statusCode;
                $c->close();
            }
            $plain = $this->client();
            $plain->get('/ws');
            $out['plain'] = $plain->statusCode;
            return $out;
        });

        self::assertSame(101, $statuses['ok']);
        self::assertSame(403, $statuses['bad'], 'false from onHandshake');
        self::assertSame(401, $statuses['expired'], 'the status of a thrown ResponseException');
        self::assertSame(500, $statuses['boom'], 'any other exception');
        self::assertSame(404, $statuses['path'], 'a path other than $path');
        self::assertSame(426, $statuses['plain'], 'a plain GET without Upgrade');
        self::assertStringContainsString('error handshake exploded', $this->waitLog('error handshake'));
    }

    public function test_send_is_refused_before_the_upgrade_and_works_from_on_open(): void
    {
        $this->startServer();

        [$early, $welcome] = self::co(function (): array {
            $a = $this->connect('/ws?token=send');
            $b = $this->connect('/ws?token=ok&name=alice');
            return [$a->recv(2)->data ?? null, $b->recv(2)->data ?? null];
        });

        self::assertSame('refused-in-handshake', $early);
        self::assertSame('welcome alice', $welcome);
    }

    // ── Messages ──────────────────────────────────────────────────────────────

    public function test_message_reaches_on_message_and_the_reply_comes_back(): void
    {
        $this->startServer();

        [$echo, $text, $binary] = self::co(function (): array {
            $c = $this->connect('/ws?token=ok');
            $c->recv(2); // welcome
            $c->push('echo:hello');
            $echo = $c->recv(2)->data ?? null;
            $c->push('bin');
            $text = $c->recv(2)->data ?? null;
            $c->push('bin', WEBSOCKET_OPCODE_BINARY);
            $binary = $c->recv(2)->data ?? null;
            return [$echo, $text, $binary];
        });

        self::assertSame('hello', $echo);
        self::assertSame('text', $text);
        self::assertSame('binary', $binary);
    }

    public function test_broadcast_reaches_everyone_but_the_sender(): void
    {
        $this->startServer();

        $got = self::co(function (): array {
            $clients = [];
            foreach (['a', 'b', 'c'] as $name) {
                $clients[$name] = $this->connect("/ws?token=ok&name={$name}");
                $clients[$name]->recv(2); // welcome
            }
            $clients['a']->push('all:hi');
            return [
                'a' => $clients['a']->recv(2)->data ?? null,
                'b' => $clients['b']->recv(2)->data ?? null,
                'c' => $clients['c']->recv(2)->data ?? null,
                'a-more' => $clients['a']->recv(0.3),
            ];
        });

        self::assertSame('sent:2', $got['a']);
        self::assertSame('hi', $got['b']);
        self::assertSame('hi', $got['c']);
        self::assertFalse($got['a-more'], 'the sender is skipped');
    }

    public function test_an_exception_in_on_message_is_logged_and_the_connection_lives_on(): void
    {
        $this->startServer();

        $after = self::co(function (): ?string {
            $c = $this->connect('/ws?token=ok');
            $c->recv(2);
            $c->push('boom');
            $c->push('echo:still here');
            return $c->recv(2)->data ?? null;
        });

        self::assertSame('still here', $after);
        self::assertStringContainsString('error message exploded', $this->waitLog('error message'));
    }

    public function test_a_slow_hook_does_not_hold_up_other_connections(): void
    {
        $this->startServer();

        [$fast, $slow] = self::co(function (): array {
            $a = $this->connect('/ws?token=ok');
            $b = $this->connect('/ws?token=ok');
            $a->recv(2);
            $b->recv(2);
            $a->push('slow');
            $t = microtime(true);
            $b->push('echo:fast');
            $b->recv(2);
            $fast = microtime(true) - $t;
            $a->recv(2);
            $slow = microtime(true) - $t;
            return [$fast, $slow];
        });

        self::assertLessThan(0.2, $fast, 'b answered while a waited in its hook');
        self::assertGreaterThanOrEqual(0.25, $slow);
    }

    public function test_many_coroutines_writing_one_connection_do_not_crash_the_process(): void
    {
        $this->startServer();

        [$frames, $alive] = self::co(function (): array {
            $c = $this->connect('/ws?token=ok');
            $c->recv(2);
            $c->push('storm');
            $frames = 0;
            while (($f = $c->recv(2)) && is_object($f)) {
                $frames++;
                if ($f->data === 'storm end') {
                    break;
                }
            }
            $c->push('echo:alive');
            return [$frames, $c->recv(2)->data ?? null];
        });

        self::assertSame(52, $frames, '50 sends + 1 broadcast + the end marker, none lost');
        self::assertSame('alive', $alive);
    }

    // ── Close ─────────────────────────────────────────────────────────────────

    public function test_server_close_reaches_the_client_and_on_close_with_its_code(): void
    {
        $this->startServer();

        $frame = self::co(function (): mixed {
            $c = $this->connect('/ws?token=ok&name=srv');
            $c->recv(2);
            $c->push('close');
            return $c->recv(2);
        });

        self::assertInstanceOf(CloseFrame::class, $frame);
        self::assertSame(4001, $frame->code);
        self::assertSame('asked', $frame->reason);
        self::assertStringContainsString('close srv 4001 asked', $this->waitLog('close srv'));
    }

    public function test_client_close_code_reaches_on_close(): void
    {
        $this->startServer();

        self::co(function (): void {
            $c = $this->connect('/ws?token=ok&name=cli');
            $c->recv(2);
            $close = new CloseFrame();
            $close->code = 4002;
            $close->reason = 'leaving';
            $c->push($close);
            $c->recv(1);
        });

        self::assertStringContainsString('close cli 4002 leaving', $this->waitLog('close cli'));
    }

    public function test_a_message_over_max_frame_size_closes_with_1009(): void
    {
        $this->startServer();

        self::co(function (): void {
            $c = $this->connect('/ws?token=ok&name=big');
            $c->recv(2);
            $c->push(str_repeat('x', 100_000));
            $c->recv(2);
        });

        self::assertStringContainsString('close big 1009 message too big', $this->waitLog('close big'));
    }

    public function test_a_foreign_origin_is_refused_before_on_handshake(): void
    {
        $this->startServer();

        $statuses = self::co(function (): array {
            $out = [];
            foreach (['own' => 'HTTPS://App.Example/', 'foreign' => 'https://evil.example'] as $case => $origin) {
                $c = $this->client();
                $c->setHeaders(['Origin' => $origin]);
                // token=boom would make onHandshake answer 500 — 403 proves it never ran.
                $c->upgrade($case === 'own' ? '/ws?token=ok' : '/ws?token=boom');
                $out[$case] = $c->statusCode;
                $c->close();
            }
            return $out;
        });

        self::assertSame(101, $statuses['own'], 'an allowed origin, compared case-insensitively');
        self::assertSame(403, $statuses['foreign'], 'an origin not in $allowedOrigins');
        self::assertStringNotContainsString('error handshake', (string) @file_get_contents($this->log));
    }

    // ── Limits and heartbeat ──────────────────────────────────────────────────

    public function test_max_connections_answers_503_to_one_more(): void
    {
        $this->startServer(['WS_MAX' => '1']);

        [$first, $second] = self::co(function (): array {
            $a = $this->connect('/ws?token=ok');
            $a->recv(2);
            $b = $this->client();
            $b->upgrade('/ws?token=ok');
            return [$a->connected, $b->statusCode];
        });

        self::assertTrue($first);
        self::assertSame(503, $second);
    }

    public function test_heartbeat_closes_a_client_that_stopped_answering(): void
    {
        $this->startServer(['WS_HEARTBEAT' => '0.3']);

        // A raw socket: does the handshake, then never answers a ping.
        $sock = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, 2);
        self::assertIsResource($sock, $error);
        fwrite($sock, "GET /ws?token=ok&name=dead HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n");
        stream_set_timeout($sock, 3);
        $head = (string) fread($sock, 4096);
        self::assertStringContainsString('101', $head);

        self::assertStringContainsString('close dead 1001 heartbeat timeout', $this->waitLog('close dead', 3.0));
        fclose($sock);
    }

    public function test_heartbeat_keeps_a_client_that_answers(): void
    {
        $this->startServer(['WS_HEARTBEAT' => '0.2']);

        $reply = self::co(function (): ?string {
            $c = $this->connect('/ws?token=ok');
            $c->recv(2);
            // Swoole's client answers a ping inside recv() — keep reading, as a browser
            // keeps answering on its own.
            $until = microtime(true) + 1.0;
            while (microtime(true) < $until) {
                $c->recv(0.05);
            }
            $c->push('echo:still open');
            return $c->recv(2)->data ?? null;
        });

        self::assertSame('still open', $reply);
    }

    // ── Stop ──────────────────────────────────────────────────────────────────

    public function test_stop_closes_every_connection_with_1001_and_the_process_exits(): void
    {
        $this->startServer();
        $pid = proc_get_status($this->server)['pid'];

        $frame = self::co(function () use ($pid): mixed {
            $c = $this->connect('/ws?token=ok&name=stopped');
            $c->recv(2);
            posix_kill($pid, SIGTERM);
            return $c->recv(3);
        });

        // Outside the scheduler: inside it Swoole swaps proc_* for its own.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && proc_get_status($this->server)['running']) {
            usleep(50_000);
        }

        self::assertInstanceOf(CloseFrame::class, $frame);
        self::assertSame(1001, $frame->code);
        self::assertFalse(proc_get_status($this->server)['running'], 'the process ended on its own');
        self::assertStringContainsString('close stopped 1001 going away', $this->waitLog('close stopped'));
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public function test_start_without_a_port_is_refused(): void
    {
        $this->expectException(WebSocketConfigException::class);
        $this->expectExceptionMessage('needs a port');
        EchoSocket::start();
    }

    public function test_dispatch_without_a_port_is_refused(): void
    {
        $this->expectException(WebSocketConfigException::class);
        EchoSocket::dispatch();
    }

    public function test_a_detached_child_without_a_numeric_port_is_refused(): void
    {
        // What a dispatched child gets: launch arguments as strings.
        $this->expectException(WebSocketConfigException::class);
        $this->expectExceptionMessage('needs a port');
        EchoSocket::startWith(['port' => 'abc']);
    }

    public function test_a_port_out_of_range_is_refused(): void
    {
        $this->expectException(WebSocketConfigException::class);
        $this->expectExceptionMessage('out of range');
        EchoSocket::start(70000);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Starts {@see EchoSocket} on a free port in its own PHP process and waits until it
     * accepts connections.
     *
     * @param array<string, string> $env
     */
    private function startServer(array $env = []): void
    {
        $this->root = sys_get_temp_dir() . '/wk_ws_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/runnable', 0777, true);
        $this->log = $this->root . '/events.log';
        $this->port = self::freePort();

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->root . '/server.php';
        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\n"
            . "Flytachi\\Winter\\Kernel\\Kernel::init(pathRoot: %s, pathStorageRunnable: %s);\n"
            . "Flytachi\\Winter\\DI\\Container::init();\n"
            . "%s::start(%d);\n",
            var_export($autoload, true),
            var_export($this->root, true),
            var_export($this->root . '/runnable', true),
            EchoSocket::class,
            $this->port,
        ));

        $this->server = proc_open(
            [PHP_BINARY, $script],
            [1 => ['file', $this->root . '/stdout.log', 'a'], 2 => ['file', $this->root . '/stdout.log', 'a']],
            $pipes,
            null,
            ['XDEBUG_MODE' => 'off', 'WS_LOG' => $this->log] + $env + getenv(),
        );
        self::assertIsResource($this->server);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, 0.2);
            if ($probe !== false) {
                fclose($probe);
                return;
            }
            if (!proc_get_status($this->server)['running']) {
                break;
            }
            usleep(50_000);
        }
        self::fail("server did not start:\n" . @file_get_contents($this->root . '/stdout.log'));
    }

    /** Waits until a line containing $needle is in the event log, and returns the log. */
    private function waitLog(string $needle, float $timeout = 2.0): string
    {
        $deadline = microtime(true) + $timeout;
        do {
            $log = (string) @file_get_contents($this->log);
            if (str_contains($log, $needle)) {
                return $log;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        return $log;
    }

    private function client(): Client
    {
        $c = new Client('127.0.0.1', $this->port);
        $c->set(['timeout' => 3, 'open_websocket_close_frame' => true]);
        return $c;
    }

    private function connect(string $uri): Client
    {
        $c = $this->client();
        $c->upgrade($uri);
        return $c;
    }

    /**
     * Runs $fn in a coroutine scheduler and returns what it returned. An exception
     * inside is carried out and rethrown here, where PHPUnit can report it.
     */
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
