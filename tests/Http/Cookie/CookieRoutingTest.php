<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use Flytachi\Winter\Kernel\Http\Cookie\Cookie;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;
use Flytachi\Winter\Kernel\Http\Response\ResponseEntity;
use Flytachi\Winter\Kernel\Http\Response\ResponseException;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;

/**
 * Cookies through a dispatched request — the wiring, not the parts.
 *
 * A handler is reached the way a real one is, so what is being checked is that
 * `Cookie::init()` really runs before the handler, that both ways of writing a cookie
 * end up on the same response, and that a cookie set before an exception still reaches
 * the client. That last one is the reason cookies are written immediately instead of
 * queued for a flush the error path would skip.
 */
final class CookieRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        Cookie::clear();
        Cookie::defaults(null);
    }

    protected function tearDown(): void
    {
        Cookie::clear();
        Cookie::defaults(null);
    }

    /** @param array<string, string> $headers */
    private function send(Router $router, string $uri = '/', array $headers = []): FakeResponse
    {
        $response = new FakeResponse();
        $router->handle(new FakeRequest('GET', $uri, $headers), $response);

        return $response;
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    public function test_a_handler_reads_the_cookies_the_client_sent(): void
    {
        $router = new Router()->get('/me', static fn(): string => Cookie::get('sid') ?? 'anonymous');

        $response = $this->send($router, '/me', ['Cookie' => 'sid=abc123']);

        self::assertSame('abc123', $response->body);
    }

    public function test_a_handler_sees_no_cookies_when_none_were_sent(): void
    {
        $router = new Router()->get('/me', static fn(): string => Cookie::get('sid') ?? 'anonymous');

        self::assertSame('anonymous', $this->send($router, '/me')->body);
    }

    /** Two dispatches in one process must not share a jar — the FPM-mode leak. */
    public function test_the_next_request_does_not_inherit_the_previous_cookies(): void
    {
        $router = new Router()->get('/me', static fn(): string => Cookie::get('sid') ?? 'anonymous');

        $this->send($router, '/me', ['Cookie' => 'sid=abc123']);

        self::assertSame('anonymous', $this->send($router, '/me')->body);
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    public function test_a_handler_can_write_a_cookie_through_the_facade(): void
    {
        $router = new Router()->get('/login', static function (): string {
            Cookie::add(Cookie::make('sid', 'issued')->expiresIn(3600));
            return 'ok';
        });

        $response = $this->send($router, '/login');

        self::assertCount(1, $response->cookies);
        self::assertStringStartsWith('sid=issued;', $response->cookies[0]);
        self::assertStringContainsString('Max-Age=3600', $response->cookies[0]);
    }

    public function test_a_handler_can_attach_a_cookie_to_the_response_entity(): void
    {
        $router = new Router()->get('/login', static fn(): ResponseEntity => ResponseEntity::ok(['ok' => true])
            ->cookie(SetCookie::make('sid', 'issued')));

        $response = $this->send($router, '/login');

        self::assertSame(['sid=issued; Path=/; HttpOnly; SameSite=Lax'], $response->cookies);
    }

    public function test_both_ways_of_writing_land_on_the_same_response(): void
    {
        $router = new Router()->get('/login', static function (): ResponseEntity {
            Cookie::add(SetCookie::make('a', '1'));
            return ResponseEntity::ok('done')->cookie(SetCookie::make('b', '2'));
        });

        $response = $this->send($router, '/login');

        self::assertCount(2, $response->cookies);
        self::assertStringStartsWith('a=1;', $response->cookies[0]);
        self::assertStringStartsWith('b=2;', $response->cookies[1]);
    }

    /**
     * The case the design is built around: a session is invalidated and only then does
     * the request fail. Had the cookie been queued for a flush on the success path, the
     * browser would have kept the dead session.
     */
    public function test_a_cookie_written_before_a_failure_still_reaches_the_client(): void
    {
        $router = new Router()->get('/logout', static function (): never {
            Cookie::forget('sid');
            throw new ResponseException('Unauthorized', \Flytachi\Winter\Base\HttpCode::UNAUTHORIZED);
        });

        $response = $this->send($router, '/logout');

        self::assertSame(401, $response->status);
        self::assertCount(1, $response->cookies, 'the deletion survived the exception');
        self::assertStringStartsWith('sid=;', $response->cookies[0]);
    }

    public function test_application_defaults_reach_a_cookie_made_in_a_handler(): void
    {
        Cookie::defaults(static fn(SetCookie $c) => $c->domain('example.com'));
        $router = new Router()->get('/login', static function (): string {
            Cookie::add(Cookie::make('sid', 'issued'));
            return 'ok';
        });

        self::assertStringContainsString('Domain=example.com', $this->send($router, '/login')->cookies[0]);
    }
}
