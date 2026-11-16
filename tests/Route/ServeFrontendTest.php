<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FrontApp\FrontServeApp;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FrontApp\NoHttpFrontApp;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\ServerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Frontends over real HTTP: a site at the root of the HTTP port beside the API, an admin
 * on a port of its own with its routes there only — and the configurations refused at boot.
 */
#[Group('integration')]
final class ServeFrontendTest extends TestCase
{
    private string $root = '';
    private int $adminPort = 0;
    private ?ServerProcess $server = null;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('serving needs the Swoole extension.');
        }
        $this->root = sys_get_temp_dir() . '/wk_front_root_' . bin2hex(random_bytes(4));
        foreach (['site', 'admin'] as $name) {
            mkdir("{$this->root}/{$name}", 0777, true);
            file_put_contents("{$this->root}/{$name}/200.html", "<html>{$name}</html>");
        }
        file_put_contents("{$this->root}/admin/logo.svg", '<svg/>');
        mkdir("{$this->root}/ssg/about", 0777, true);
        file_put_contents("{$this->root}/ssg/index.html", '<html>home</html>');
        file_put_contents("{$this->root}/ssg/about/index.html", '<html>about</html>');
        $this->adminPort = ServerProcess::freePort();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, string> $env */
    private function start(string $app = FrontServeApp::class, array $env = [], bool $httpFlags = true): bool
    {
        $this->server = new ServerProcess(env: [
            'WK_FRONT_ROOT' => $this->root,
            'WK_FRONT_ADMIN_PORT' => (string) $this->adminPort,
            'LOG_LEVEL' => 'info',
            ...$env,
        ], app: $app, httpFlags: $httpFlags);
        return $this->server->start();
    }

    /** @return array{status: int, body: string} */
    private function get(int $port, string $path, string $accept = 'text/html'): array
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 3.0);
        self::assertNotFalse($socket, "port {$port}: {$error}");
        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nAccept: {$accept}\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);
        [$head, $body] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        preg_match('#^HTTP/\S+ (\d{3})#', $head, $m);
        return ['status' => (int) ($m[1] ?? 0), 'body' => $body];
    }

    public function test_the_root_site_and_the_api_share_the_http_port(): void
    {
        self::assertTrue($this->start(), $this->server?->log() ?? '');
        $http = $this->server->port();

        self::assertSame('<html>site</html>', $this->get($http, '/employees/5')['body']);
        self::assertSame('pong', $this->get($http, '/api/ping', 'application/json')['body']);
    }

    public function test_the_admin_port_serves_its_files_and_its_routes(): void
    {
        self::assertTrue($this->start(), $this->server?->log() ?? '');

        self::assertSame('<html>admin</html>', $this->get($this->adminPort, '/users')['body']);
        self::assertSame('<svg/>', $this->get($this->adminPort, '/logo.svg')['body']);
        self::assertSame('users', $this->get($this->adminPort, '/admin/users', 'application/json')['body']);
        self::assertSame(404, $this->get($this->adminPort, '/api/ping', 'application/json')['status'], 'not its route');
    }

    public function test_the_admin_prefix_is_404_on_the_http_port(): void
    {
        self::assertTrue($this->start(), $this->server?->log() ?? '');

        $response = $this->get($this->server->port(), '/admin/users', 'application/json');
        self::assertSame(404, $response['status']);
        self::assertStringNotContainsString('users', $response['body']);
    }

    public function test_a_frontend_without_http_refuses_to_boot(): void
    {
        self::assertFalse($this->start(NoHttpFrontApp::class, httpFlags: false));
        self::assertStringContainsString('a frontend needs the HTTP server', $this->server->log());
    }

    public function test_two_frontends_on_one_port_refuse_to_boot(): void
    {
        self::assertFalse($this->start(env: ['WK_FRONT_CASE' => 'same-port']));
        self::assertStringContainsString("both take port {$this->adminPort}", $this->server->log());
    }

    public function test_a_frontend_on_the_http_servers_port_refuses_to_boot(): void
    {
        self::assertFalse($this->start(env: ['WK_FRONT_CASE' => 'http-port']));
        self::assertStringContainsString('is the HTTP server\'s', $this->server->log());
    }

    public function test_a_frontend_on_a_websockets_port_refuses_to_boot(): void
    {
        self::assertFalse($this->start(env: ['WK_FRONT_CASE' => 'ws-port']));
        self::assertStringContainsString("Port {$this->adminPort} is used twice", $this->server->log());
    }

    public function test_a_controller_under_a_sites_path_is_warned_and_wins(): void
    {
        self::assertTrue($this->start(env: ['WK_FRONT_CASE' => 'overlap']), $this->server?->log() ?? '');

        self::assertSame('the controller', $this->get($this->server->port(), '/taken')['body']);
        self::assertStringContainsString('GET /taken is a controller\'s route: the frontend "taken" is not reachable at /taken', $this->server->log());
    }

    public function test_overlap_false_silences_that_warning(): void
    {
        self::assertTrue($this->start(env: ['WK_FRONT_CASE' => 'overlap-silent']), $this->server?->log() ?? '');

        self::assertSame('the controller', $this->get($this->server->port(), '/taken')['body']);
        self::assertStringNotContainsString('is a controller\'s route', $this->server->log());
    }

    public function test_an_ssg_page_taken_by_a_controller_is_warned(): void
    {
        self::assertTrue($this->start(env: ['WK_FRONT_CASE' => 'ssg']), $this->server?->log() ?? '');

        self::assertSame('<html>home</html>', $this->get($this->server->port(), '/')['body']);
        self::assertSame('the controller about', $this->get($this->server->port(), '/about')['body']);
        self::assertStringContainsString('GET /about is a controller\'s route: the frontend "site"', $this->server->log());
    }

    public function test_the_frontends_port_takes_the_servers_request_size(): void
    {
        self::assertTrue($this->start(env: ['SERVER_MAX_REQUEST_SIZE' => '8M']), $this->server?->log() ?? '');

        $body = str_repeat('x', 5 * 1024 * 1024);
        $socket = stream_socket_client("tcp://127.0.0.1:{$this->adminPort}", $errno, $error, 5.0);
        fwrite($socket, "POST /admin/upload HTTP/1.0\r\nHost: x\r\nContent-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        self::assertStringStartsWith('HTTP/1.1 200', $raw, substr($raw, 0, 200));
        self::assertStringEndsWith((string) strlen($body), $raw);
    }

    public function test_an_unreadable_folder_does_not_stop_the_boot(): void
    {
        mkdir("{$this->root}/ssg/locked");
        chmod("{$this->root}/ssg/locked", 0000);
        try {
            self::assertTrue($this->start(env: ['WK_FRONT_CASE' => 'ssg']), $this->server?->log() ?? '');
            self::assertSame('<html>home</html>', $this->get($this->server->port(), '/')['body']);
        } finally {
            chmod("{$this->root}/ssg/locked", 0755);
        }
    }

    public function test_a_frontends_hints_are_logged_at_start(): void
    {
        self::assertTrue($this->start(env: ['WK_FRONT_HINTS' => 'set ssr: false|run generate']), $this->server?->log() ?? '');

        self::assertMatchesRegularExpression('/WARN.*the frontend "site": set ssr: false/', $this->server->log());
        self::assertStringContainsString('the frontend "site": run generate', $this->server->log());
    }

    public function test_a_throwing_hints_check_does_not_stop_the_start(): void
    {
        self::assertTrue($this->start(env: ['WK_FRONT_HINTS' => 'throw']), $this->server?->log() ?? '');

        self::assertSame(200, $this->get($this->server->port(), '/employees')['status']);
        self::assertStringContainsString('the frontend "site": its check failed: broken check', $this->server->log());
    }
}
