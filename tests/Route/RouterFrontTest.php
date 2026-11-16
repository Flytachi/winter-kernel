<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\Http\Front\SiteMap;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;

/**
 * The router with frontends: a controller always wins, a frontend takes the GET and HEAD
 * no route took, and the routes a frontend's own port lets through are there only.
 */
final class RouterFrontTest extends TestCase
{
    private const int HTTP = 8000;
    private const int ADMIN = 9091;
    private const array HTML = ['Accept' => 'text/html,application/xhtml+xml'];
    private const array JSON = ['Accept' => 'application/json'];

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_router_front_' . bin2hex(random_bytes(4));
        foreach (['site', 'admin', 'operator'] as $name) {
            mkdir("{$this->dir}/{$name}", 0777, true);
            file_put_contents("{$this->dir}/{$name}/200.html", "<html>{$name}</html>");
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function router(bool $sites = true): Router
    {
        $router = new Router()
            ->get('/api/ping', static fn(): string => 'pong')
            ->post('/login', static fn(): string => 'logged in')
            ->get('/admin/users', static fn(): string => 'users')
            ->get('/operator/tasks', static fn(): string => 'tasks')
            ->get('/health', static fn(): string => 'up');
        if ($sites) {
            $router->withSites(new SiteMap([
                new FrontSite('site', "{$this->dir}/site"),
                new FrontSite('admin', "{$this->dir}/admin", port: self::ADMIN, routes: ['/admin']),
                new FrontSite('operator', "{$this->dir}/operator", port: 9092, routes: ['/operator']),
            ], self::HTTP));
        }
        return $router;
    }

    /** @param array<string, string> $headers */
    private function send(Router $router, string $method, string $path, array $headers = [], int $port = self::HTTP): FakeResponse
    {
        $response = new FakeResponse();
        $router->handle(new FakeRequest($method, $path, $headers, server: ['server_port' => $port]), $response);
        return $response;
    }

    private function shellOf(string $name): string
    {
        return (string) realpath("{$this->dir}/{$name}/200.html");
    }

    public function test_without_sites_nothing_changes(): void
    {
        $router = $this->router(sites: false);

        $missing = $this->send($router, 'GET', '/employees', self::JSON);
        self::assertSame(404, $missing->status);
        self::assertSame(['code' => 404, 'message' => 'Not Found'], $missing->json());
        self::assertNull($this->send($router, 'GET', '/employees', self::HTML)->sentFile);

        $wrongMethod = $this->send($router, 'GET', '/login', self::HTML);
        self::assertSame(405, $wrongMethod->status);
        self::assertStringContainsString('POST', (string) $wrongMethod->header_('Allow'));
    }

    public function test_a_route_wins_over_the_site(): void
    {
        $response = $this->send($this->router()->get('/', static fn(): string => 'controller'), 'GET', '/', self::HTML);

        self::assertSame('controller', $response->body);
        self::assertNull($response->sentFile);
    }

    public function test_a_get_without_a_route_goes_to_the_site(): void
    {
        $response = $this->send($this->router(), 'GET', '/employees/5', self::HTML);

        self::assertSame($this->shellOf('site'), $response->sentFile);
    }

    public function test_a_post_without_a_route_is_a_json_404_not_the_shell(): void
    {
        $html = $this->send($this->router(), 'POST', '/api/emplyees', self::HTML);
        self::assertSame(404, $html->status);
        self::assertNull($html->sentFile, 'the backend answers, not the shell');

        $api = $this->send($this->router(), 'POST', '/api/emplyees', self::JSON);
        self::assertSame(404, $api->json()['code'] ?? null);
    }

    public function test_an_api_client_on_an_unknown_get_gets_a_json_404(): void
    {
        $response = $this->send($this->router(), 'GET', '/api/emplyees', self::JSON);

        self::assertSame(404, $response->status);
        self::assertNull($response->sentFile);
    }

    public function test_a_get_on_a_post_only_path_goes_to_the_site_for_html_and_405_otherwise(): void
    {
        self::assertSame($this->shellOf('site'), $this->send($this->router(), 'GET', '/login', self::HTML)->sentFile);

        $api = $this->send($this->router(), 'GET', '/login', self::JSON);
        self::assertSame(405, $api->status);
        self::assertNull($api->sentFile);
    }

    public function test_a_prefix_taken_by_a_site_port_is_404_on_the_http_port(): void
    {
        $response = $this->send($this->router(), 'GET', '/admin/users', self::JSON);

        self::assertSame(404, $response->status);
        self::assertNotSame('users', $response->body);
    }

    public function test_the_sites_port_reaches_its_routes(): void
    {
        self::assertSame('users', $this->send($this->router(), 'GET', '/admin/users', self::JSON, self::ADMIN)->body);
        self::assertSame($this->shellOf('admin'), $this->send($this->router(), 'GET', '/users', self::HTML, self::ADMIN)->sentFile);
    }

    public function test_the_sites_port_does_not_reach_other_controllers(): void
    {
        foreach (['/health', '/api/ping'] as $path) {
            $html = $this->send($this->router(), 'GET', $path, self::HTML, self::ADMIN);
            self::assertSame($this->shellOf('admin'), $html->sentFile, $path);

            $json = $this->send($this->router(), 'GET', $path, self::JSON, self::ADMIN);
            self::assertSame(404, $json->status, $path);
            self::assertNotSame('up', $json->body);
            self::assertNotSame('pong', $json->body);
        }
    }

    public function test_another_sites_prefix_is_not_reachable_on_this_port(): void
    {
        $response = $this->send($this->router(), 'GET', '/operator/tasks', self::JSON, self::ADMIN);

        self::assertSame(404, $response->status);
        self::assertNotSame('tasks', $response->body);
    }

    public function test_head_reaches_the_site_like_get(): void
    {
        $response = $this->send($this->router(), 'HEAD', '/employees', self::HTML);

        self::assertSame($this->shellOf('site'), $response->sentFile);
        self::assertNotNull($response->header_('Content-Length'));
    }

    public function test_an_unknown_path_under_the_sites_routes_is_the_backends_404(): void
    {
        $response = $this->send($this->router(), 'GET', '/admin/typo', ['Accept' => '*/*'], self::ADMIN);

        self::assertSame(404, $response->status);
        self::assertNull($response->sentFile, 'its routes are controller territory: not the shell');
    }
}
