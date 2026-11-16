<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FrontApp\FrontServeApp;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\ServerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A frontend with a server of its own over real HTTP: the program runs under the
 * supervisor on a loopback port, the HTTP server forwards to it what no route took, and
 * the program reaches the controllers on a loopback port of the backend's own.
 */
#[Group('integration')]
final class ServeFrontendProgramTest extends TestCase
{
    private string $root = '';
    private ?ServerProcess $server = null;
    private int $adminPort = 0;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('serving needs the Swoole extension.');
        }
        $this->root = sys_get_temp_dir() . '/wk_front_prog_' . bin2hex(random_bytes(4));
        foreach (['site', 'admin'] as $name) {
            mkdir("{$this->root}/{$name}", 0777, true);
            file_put_contents("{$this->root}/{$name}/200.html", "<html>{$name}</html>");
        }
        file_put_contents("{$this->root}/site/robots.txt", 'built');
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, string> $env */
    private function start(string $program, array $env = [], bool $dev = false): bool
    {
        $this->server = new ServerProcess(env: [
            'WK_FRONT_ROOT' => $this->root,
            'WK_FRONT_ADMIN_PORT' => (string) ($this->adminPort = ServerProcess::freePort()),
            'WK_FRONT_PROGRAM' => $program,
            'LOG_LEVEL' => 'info',
            ...$env,
        ], app: FrontServeApp::class, dev: $dev);
        return $this->server->start();
    }

    /** Waits for the program's first line: its port and the backend it was given. */
    private function program(): array
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (preg_match('#listening on 127\.0\.0\.1:(\d+) backend=http://127\.0\.0\.1:(\d+)#', $this->log(), $m) === 1
                && preg_match('#(site|admin) is up\.#', $this->log()) === 1) {
                return ['port' => (int) $m[1], 'backend' => (int) $m[2]];
            }
            usleep(100_000);
        }
        self::fail("the program did not come up:\n" . $this->log());
    }

    private function log(): string
    {
        return $this->server?->log() ?? '';
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private function send(int $port, string $method, string $path, array $headers = [], string $body = '', float $timeout = 5.0): array
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 3.0);
        self::assertNotFalse($socket, "port {$port}: {$error}");
        stream_set_timeout($socket, (int) ceil($timeout));
        $headers += ['Host' => "127.0.0.1:{$port}", 'Accept' => 'text/html', 'Connection' => 'close'];
        if ($body !== '') {
            $headers['Content-Length'] = (string) strlen($body);
        }
        $head = "{$method} {$path} HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        fwrite($socket, $head . "\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);
        [$top, $rest] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        $lines = explode("\r\n", $top);
        preg_match('#^HTTP/\S+ (\d{3})#', (string) array_shift($lines), $m);
        $parsed = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2) + ['', ''];
            $parsed[strtolower(trim($name))][] = trim($value);
        }
        if (($parsed['transfer-encoding'][0] ?? '') === 'chunked') {
            $decoded = '';
            while ($rest !== '' && ($eol = strpos($rest, "\r\n")) !== false) {
                $size = hexdec(substr($rest, 0, $eol));
                if ($size === 0) {
                    break;
                }
                $decoded .= substr($rest, $eol + 2, (int) $size);
                $rest = substr($rest, $eol + 2 + (int) $size + 2);
            }
            $rest = $decoded;
        }
        return ['status' => (int) ($m[1] ?? 0), 'headers' => $parsed, 'body' => $rest];
    }

    /** @return array<string, mixed> What the upstream says it got. */
    private function echoed(array $response): array
    {
        self::assertSame(200, $response['status'], $response['body'] . "\n" . $this->log());
        return (array) json_decode($response['body'], true);
    }

    private function http(): int
    {
        return (int) $this->server?->port();
    }

    // ── Task 2: the program and the backend port ─────────────────────────────

    public function test_the_program_runs_with_its_port_and_the_backend(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $program = $this->program();

        self::assertNotSame($program['port'], $program['backend']);
        $api = $this->send($program['backend'], 'GET', '/api/ping');
        self::assertSame(200, $api['status']);
        self::assertSame('pong', $api['body'], 'the backend port reaches the controllers');
        self::assertSame(404, $this->send($program['backend'], 'GET', '/admin/users')['status'], 'a prefix closed on its port is closed for it');
        self::assertSame(404, $this->send($program['backend'], 'GET', '/nowhere')['status'], 'never the frontend');
    }

    public function test_a_program_that_cannot_start_does_not_stop_the_application(): void
    {
        self::assertTrue($this->start('broken'), $this->log());

        self::assertSame('pong', $this->send($this->http(), 'GET', '/api/ping')['body']);
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline && !str_contains($this->log(), 'site failed')) {
            usleep(200_000);
        }
        self::assertStringContainsString('site failed', $this->log());
        self::assertSame('pong', $this->send($this->http(), 'GET', '/api/ping')['body'], 'the application runs on');
    }

    public function test_stopping_leaves_nothing_on_the_programs_port(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $program = $this->program();
        $child = (int) $this->send($program['port'], 'GET', '/fork')['body'];
        self::assertGreaterThan(0, $child, $this->log());

        $this->server?->signal(SIGTERM);
        self::assertTrue($this->server?->awaitExit(15.0), $this->log());

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && @posix_getpgid($child) !== false) {
            usleep(100_000);
        }
        self::assertFalse(@posix_getpgid($child), 'the program\'s child is gone with it');
        self::assertFalse(@fsockopen('127.0.0.1', $program['port'], $errno, $error, 0.5), 'its port is free');
    }

    public function test_no_program_no_backend_listener(): void
    {
        self::assertTrue($this->start(''), $this->log());
        $pid = $this->server?->pid();

        $ports = (string) shell_exec("lsof -nP -a -iTCP -sTCP:LISTEN -g {$pid} 2>/dev/null || true");
        self::assertStringNotContainsString('127.0.0.1:', preg_replace('#127\.0\.0\.1:' . $this->http() . '\b#', '', $ports));
    }

    // ── Task 3: forwarding ───────────────────────────────────────────────────

    public function test_a_page_no_route_took_goes_to_the_program(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $this->program();

        $echo = $this->echoed($this->send($this->http(), 'GET', '/users/7?tab=a'));
        self::assertSame('GET', $echo['method']);
        self::assertSame('/users/7?tab=a', $echo['uri']);
        self::assertSame('pong', $this->send($this->http(), 'GET', '/api/ping')['body'], 'a controller still wins');
    }

    public function test_pages_only_keep_the_api_404_for_a_post(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $this->program();

        $post = $this->send($this->http(), 'POST', '/api/typo', ['Content-Type' => 'application/json'], '{}');
        self::assertSame(404, $post['status']);
        self::assertArrayNotHasKey('x-upstream', $post['headers']);
    }

    public function test_a_server_frontend_gets_a_post_no_route_took(): void
    {
        self::assertTrue($this->start('server'), $this->log());
        $this->program();

        $echo = $this->echoed($this->send($this->http(), 'POST', '/api/_nitro', ['Content-Type' => 'application/json'], '{"a":1}'));
        self::assertSame('POST', $echo['method']);
        self::assertSame(7, $echo['length']);
        self::assertSame(405, $this->send($this->http(), 'PUT', '/api/ping', ['Accept' => 'application/json'])['status'], 'a known path keeps its 405');
    }

    public function test_an_exclusive_prefix_never_reaches_its_controllers_on_the_http_port(): void
    {
        self::assertTrue($this->start('server'), $this->log());
        $this->program();

        $admin = $this->send($this->http(), 'GET', '/admin/users', ['Accept' => 'application/json']);
        self::assertNotSame('users', $admin['body'], 'the admin API stays on its own port');
        self::assertSame('yes', $admin['headers']['x-upstream'][0] ?? '', 'there it is the frontend\'s, as any path no route took');
    }

    public function test_a_closed_prefix_stays_closed_through_the_programs_backend(): void
    {
        self::assertTrue($this->start('server'), $this->log());
        $this->program();

        $through = $this->echoed($this->send($this->http(), 'GET', '/via-backend?to=/admin/users'));
        self::assertSame(404, $through['status'], 'a public request handed on by the program meets the HTTP port\'s rules');
        self::assertNotSame('users', $through['body']);
        $api = $this->echoed($this->send($this->http(), 'GET', '/via-backend?to=/api/ping'));
        self::assertSame('pong', $api['body'], 'the rest of the API is there');
    }

    public function test_a_built_file_is_sent_without_the_program(): void
    {
        self::assertTrue($this->start('server'), $this->log());
        $this->program();

        $file = $this->send($this->http(), 'GET', '/robots.txt', ['Accept' => '*/*']);
        self::assertSame('built', $file['body']);
        self::assertArrayNotHasKey('x-upstream', $file['headers']);
    }

    public function test_dev_never_serves_the_old_build(): void
    {
        self::assertTrue($this->start('pages', dev: true), $this->log());
        $this->program();

        $echo = $this->echoed($this->send($this->http(), 'GET', '/robots.txt', ['Accept' => '*/*']));
        self::assertSame('/robots.txt', $echo['uri']);
    }

    public function test_the_client_and_its_host_reach_the_program(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $this->program();

        $echo = $this->echoed($this->send($this->http(), 'GET', '/x', ['Host' => 'shop.example:8000', 'X-Forwarded-For' => '203.0.113.9']));
        self::assertSame('shop.example:8000', $echo['host']);
        self::assertSame('203.0.113.9, 127.0.0.1', $echo['xff']);
        self::assertSame('http', $echo['xfproto']);
        self::assertSame('shop.example:8000', $echo['xfhost']);
        self::assertNotSame('close', $echo['connection'], 'hop-by-hop headers stay here');
    }

    public function test_dev_asks_the_program_as_localhost(): void
    {
        self::assertTrue($this->start('pages', dev: true), $this->log());
        $program = $this->program();

        $echo = $this->echoed($this->send($this->http(), 'GET', '/x', ['Host' => 'shop.example:8000']));
        self::assertSame("localhost:{$program['port']}", $echo['host']);
        self::assertSame('shop.example:8000', $echo['xfhost']);
    }

    public function test_a_body_the_program_compressed_reaches_the_client_whole(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $this->program();

        $plain = $this->send($this->http(), 'GET', '/gzip', ['Accept' => '*/*']);
        self::assertSame(200, $plain['status'], $this->log());
        self::assertArrayNotHasKey('content-encoding', $plain['headers']);
        self::assertSame('compressed by the upstream', $plain['body'], 'unpacked for a client that did not ask');

        $gzip = $this->send($this->http(), 'GET', '/gzip', ['Accept-Encoding' => 'gzip', 'Accept' => '*/*']);
        $body = ($gzip['headers']['content-encoding'][0] ?? '') === 'gzip' ? gzdecode($gzip['body']) : $gzip['body'];
        self::assertSame('compressed by the upstream', $body, 'its encoding says what the bytes are');
        $echo = $this->echoed($this->send($this->http(), 'GET', '/x', ['Accept-Encoding' => 'gzip, br']));
        self::assertSame(200, $this->send($this->http(), 'GET', '/x')['status']);
        self::assertIsArray($echo);
    }

    public function test_every_set_cookie_comes_back(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $this->program();

        $cookies = $this->send($this->http(), 'GET', '/cookies');
        self::assertCount(2, $cookies['headers']['set-cookie'] ?? [], print_r($cookies['headers'], true));
    }

    public function test_head_has_no_body_and_keeps_the_status(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $this->program();

        $head = $this->send($this->http(), 'HEAD', '/x');
        self::assertSame(200, $head['status']);
        self::assertSame('', $head['body']);
        self::assertSame(418, $this->send($this->http(), 'GET', '/status')['status'], 'the program\'s status as it is');
    }

    public function test_a_large_post_reaches_the_program(): void
    {
        self::assertTrue($this->start('server'), $this->log());
        $this->program();

        $echo = $this->echoed($this->send($this->http(), 'POST', '/upload', ['Content-Type' => 'application/octet-stream'], str_repeat('x', 5 * 1024 * 1024), 15.0));
        self::assertSame(5 * 1024 * 1024, $echo['length']);
    }

    public function test_a_multipart_post_reaches_the_program(): void
    {
        self::assertTrue($this->start('server'), $this->log());
        $this->program();

        $body = "--b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nhello\r\n--b--\r\n";
        $echo = $this->echoed($this->send($this->http(), 'POST', '/form', ['Content-Type' => 'multipart/form-data; boundary=b'], $body));
        self::assertSame(['file'], $echo['files'], 'the program parses the same body');
    }

    public function test_a_program_not_listening_is_503(): void
    {
        self::assertTrue($this->start('broken'), $this->log());

        $page = $this->send($this->http(), 'GET', '/users/7');
        self::assertSame(503, $page['status']);
        self::assertSame('2', $page['headers']['retry-after'][0] ?? '');
        self::assertSame('no-store', $page['headers']['cache-control'][0] ?? '');
    }

    public function test_a_program_that_hangs_is_504_and_the_api_answers_meanwhile(): void
    {
        self::assertTrue($this->start('pages', ['SERVER_REQUEST_TIMEOUT' => '3']), $this->log());
        $this->program();

        $started = microtime(true);
        $pending = stream_socket_client("tcp://127.0.0.1:{$this->http()}", $errno, $error, 3.0);
        fwrite($pending, "GET /hang HTTP/1.1\r\nHost: x\r\nAccept: text/html\r\nConnection: close\r\n\r\n");
        self::assertSame('pong', $this->send($this->http(), 'GET', '/api/ping')['body'], 'the worker is not held');
        stream_set_timeout($pending, 10);
        $raw = (string) stream_get_contents($pending);
        self::assertMatchesRegularExpression('#^HTTP/1\.1 504#', $raw, $this->log());
        self::assertLessThan(8.0, microtime(true) - $started);
    }

    // ── Task 4: the HMR socket under `call run dev` ──────────────────────────

    /**
     * Opens a WebSocket to the HTTP port, says the words, and tells what came back.
     *
     * @param list<string> $words
     * @return array{upgraded: bool, status: int, protocol: ?string, said: list<string>}
     */
    private function socket(string $path, array $words = [], ?int $port = null): array
    {
        $command = [PHP_BINARY, __DIR__ . '/Fixtures/FrontApp/socket-client.php', (string) ($port ?? $this->http()), $path, ...$words];
        $out = (string) shell_exec(implode(' ', array_map('escapeshellarg', $command)));
        $result = json_decode($out, true);
        self::assertIsArray($result, $out);
        return $result;
    }

    public function test_the_hmr_socket_reaches_the_program(): void
    {
        self::assertTrue($this->start('pages', dev: true), $this->log());
        $this->program();

        $socket = $this->socket('/_nuxt/?token=abc', ['one', 'two']);

        self::assertSame(101, $socket['status'], $this->log());
        self::assertSame('vite-hmr', $socket['protocol']);
        self::assertSame(['up:one', 'up:two'], $socket['said']);
        self::assertSame('pong', $this->send($this->http(), 'GET', '/api/ping')['body'], 'plain HTTP as before');
    }

    public function test_the_hmr_socket_works_on_a_frontends_own_port(): void
    {
        self::assertTrue($this->start('pages', ['WK_FRONT_PROGRAM_ON' => 'admin'], dev: true), $this->log());
        $this->program();

        $socket = $this->socket('/_nuxt/', ['one'], $this->adminPort);
        self::assertSame(101, $socket['status'], $this->log());
        self::assertSame(['up:one'], $socket['said']);
    }

    public function test_the_program_closing_closes_the_client(): void
    {
        self::assertTrue($this->start('pages', dev: true), $this->log());
        $this->program();

        $socket = $this->socket('/_nuxt/', ['bye']);

        self::assertSame(['closed'], $socket['said']);
    }

    public function test_a_socket_off_the_frontend_is_refused(): void
    {
        self::assertTrue($this->start('pages', dev: true), $this->log());
        $this->program();

        $socket = $this->socket('/api/ping');
        // The status is the answer: the coroutine client on Linux says "upgraded" to a 200.
        self::assertSame(404, $socket['status']);
    }

    public function test_production_keeps_the_plain_http_server(): void
    {
        self::assertTrue($this->start('pages'), $this->log());
        $this->program();

        $socket = $this->socket('/_nuxt/');
        self::assertNotSame(101, $socket['status'], 'no relay outside call run dev');
        self::assertNull($socket['protocol']);
    }
}
