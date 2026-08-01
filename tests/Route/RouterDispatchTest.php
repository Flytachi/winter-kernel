<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Route;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Response\ResponseException;
use Flytachi\Winter\K2\Route\Router;
use Flytachi\Winter\K2\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\K2\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Dispatch through {@see Router::handle()} — the path every HTTP request takes.
 *
 * Routes are registered by hand and handled with request/response doubles, so the
 * matching, the status codes and the response serialisation are exercised without a
 * server, a container or a kernel boot.
 */
final class RouterDispatchTest extends TestCase
{
    private function send(Router $router, string $method, string $uri): FakeResponse
    {
        $response = new FakeResponse();
        $router->handle(new FakeRequest($method, $uri), $response);

        return $response;
    }

    // ── Matching ───────────────────────────────────────────────────────────────

    public function test_a_static_route_reaches_its_handler(): void
    {
        $router = new Router()->get('/ping', static fn(): string => 'pong');

        $response = $this->send($router, 'GET', '/ping');

        self::assertSame(200, $response->status);
        self::assertSame('pong', $response->body);
    }

    public function test_a_dynamic_segment_is_passed_to_the_handler(): void
    {
        $router = new Router()->get('/users/{id:\d+}', static fn($req, $res, array $p): array => ['id' => $p['id']]);

        $response = $this->send($router, 'GET', '/users/42');

        self::assertSame(200, $response->status);
        self::assertSame(['id' => '42'], $response->json());
    }

    public function test_a_segment_constraint_is_enforced(): void
    {
        $router = new Router()->get('/users/{id:\d+}', static fn(): string => 'never');

        $response = $this->send($router, 'GET', '/users/abc');

        self::assertSame(404, $response->status, 'a non-numeric id must not match \d+');
    }

    public function test_an_unknown_path_is_not_found(): void
    {
        $router = new Router()->get('/ping', static fn(): string => 'pong');

        $response = $this->send($router, 'GET', '/nope');

        self::assertSame(404, $response->status);
        self::assertSame(['code' => 404, 'message' => 'Not Found'], $response->json());
    }

    public function test_a_known_path_with_the_wrong_method_reports_what_is_allowed(): void
    {
        $router = new Router()
            ->post('/users', static fn(): string => 'created')
            ->put('/users', static fn(): string => 'replaced');

        $response = $this->send($router, 'DELETE', '/users');

        self::assertSame(405, $response->status);
        self::assertNotNull($response->header_('Allow'));
        foreach (['POST', 'PUT'] as $method) {
            self::assertStringContainsString($method, (string) $response->header_('Allow'));
        }
    }

    public function test_each_verb_helper_registers_its_own_method(): void
    {
        $router = new Router();
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
            $router->{$verb}('/thing', static fn(): string => $verb);
        }

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(200, $this->send($router, $method, '/thing')->status, $method);
        }
    }

    public function test_options_is_answered_before_dispatch(): void
    {
        $router = new Router()->post('/users', static fn(): string => 'created');

        $response = $this->send($router, 'OPTIONS', '/users');

        self::assertSame(204, $response->status, 'preflight is intercepted, the handler never runs');
        self::assertSame('', $response->body);
    }

    // ── Registration guards ────────────────────────────────────────────────────

    public function test_a_duplicate_static_route_is_rejected(): void
    {
        $router = new Router()->get('/ping', static fn(): string => 'a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous handler methods mapped');

        $router->get('/ping', static fn(): string => 'b');
    }

    public function test_a_duplicate_dynamic_route_is_rejected(): void
    {
        $router = new Router()->get('/users/{id}', static fn(): string => 'a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous handler methods mapped');

        $router->get('/users/{id}', static fn(): string => 'b');
    }

    public function test_the_same_path_under_different_methods_is_fine(): void
    {
        $router = new Router()
            ->get('/users', static fn(): string => 'list')
            ->post('/users', static fn(): string => 'create');

        self::assertSame('list', $this->send($router, 'GET', '/users')->body);
        self::assertSame('create', $this->send($router, 'POST', '/users')->body);
    }

    // ── Response serialisation ─────────────────────────────────────────────────

    public function test_an_array_return_is_serialised_as_json(): void
    {
        $router = new Router()->get('/j', static fn(): array => ['a' => 1, 'b' => [2, 3]]);

        self::assertSame(['a' => 1, 'b' => [2, 3]], $this->send($router, 'GET', '/j')->json());
    }

    public function test_a_null_return_sends_nothing(): void
    {
        // The handler took over the response itself; the router must not write over it.
        $router = new Router()->get('/silent', static fn(): null => null);

        $response = $this->send($router, 'GET', '/silent');

        self::assertFalse($response->ended);
        self::assertNull($response->status);
    }

    // ── Failures ───────────────────────────────────────────────────────────────

    public function test_an_unexpected_exception_becomes_a_500(): void
    {
        $router = new Router()->get('/boom', static function (): never {
            throw new RuntimeException('kaboom');
        });

        $response = $this->send($router, 'GET', '/boom');

        self::assertSame(500, $response->status);
        self::assertSame('kaboom', $response->json()['message'] ?? null);
    }

    public function test_a_response_exception_carries_its_own_status(): void
    {
        $router = new Router()->get('/teapot', static function (): never {
            throw new ResponseException('short and stout', HttpCode::IM_A_TEAPOT);
        });

        $response = $this->send($router, 'GET', '/teapot');

        self::assertSame(418, $response->status);
        self::assertSame('short and stout', $response->json()['message'] ?? null);
    }

    // ── Introspection ──────────────────────────────────────────────────────────

    public function test_the_route_summary_lists_every_registration(): void
    {
        $router = new Router()
            ->get('/a', static fn(): string => 'a')
            ->post('/b/{id:\d+}', static fn(): string => 'b');

        $summary = $router->getRoutesSummary();

        $pairs = array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], $summary);
        self::assertContains('GET /a', $pairs);
        self::assertContains('POST /b/{id:\d+}', $pairs);
    }
}
