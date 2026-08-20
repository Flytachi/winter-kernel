<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use Flytachi\Winter\Kernel\Http\Cookie\Cookie;
use Flytachi\Winter\Kernel\Http\Cookie\SameSite;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;
use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Tests\Http\Fixtures\OriginProbeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The per-request cookie facade.
 *
 * Two things are being pinned here. Reading goes through the request's parsed cookies,
 * so the facade inherits the parser's freedom from `$_COOKIE`'s name rewriting. Writing
 * reaches the response immediately, the way `header()` does — nothing is queued for a
 * flush that a thrown exception could skip, which is exactly when clearing a session
 * cookie matters most.
 */
final class CookieTest extends TestCase
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

    private function boot(string $cookieHeader = '', string $scheme = 'http'): FakeResponse
    {
        $request  = new OriginProbeRequest(
            scheme: $scheme,
            headers: $cookieHeader === '' ? [] : ['Cookie' => $cookieHeader],
        );
        $response = new FakeResponse();

        Header::init($request);
        Cookie::init($request, $response);

        return $response;
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    public function test_a_sent_cookie_is_readable(): void
    {
        $this->boot('sid=abc; theme=dark');

        self::assertSame('abc', Cookie::get('sid'));
        self::assertSame('dark', Cookie::get('theme'));
    }

    public function test_an_absent_cookie_reads_as_null(): void
    {
        $this->boot('sid=abc');

        self::assertNull(Cookie::get('nope'));
        self::assertFalse(Cookie::has('nope'));
    }

    /** An empty value is still a cookie the client sent — has() must not conflate them. */
    public function test_an_empty_cookie_is_present(): void
    {
        $this->boot('consent=');

        self::assertTrue(Cookie::has('consent'));
        self::assertSame('', Cookie::get('consent'));
    }

    public function test_all_returns_the_whole_map(): void
    {
        $this->boot('a=1; b=2');

        self::assertSame(['a' => '1', 'b' => '2'], Cookie::all());
    }

    public function test_reading_without_a_request_is_empty_rather_than_fatal(): void
    {
        self::assertSame([], Cookie::all());
        self::assertNull(Cookie::get('sid'));
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    public function test_add_writes_to_the_response_at_once(): void
    {
        $response = $this->boot();

        Cookie::add(SetCookie::make('sid', 'abc'));

        self::assertSame(['sid=abc; Path=/; HttpOnly; SameSite=Lax'], $response->cookies);
    }

    /** The header a map keyed by name could never carry twice. */
    public function test_several_cookies_all_survive(): void
    {
        $response = $this->boot();

        Cookie::add(SetCookie::make('a', '1'));
        Cookie::add(SetCookie::make('b', '2'));
        Cookie::add(SetCookie::make('c', '3'));

        self::assertCount(3, $response->cookies);
        self::assertSame([], $response->headers, 'cookies never touch the header map');
    }

    public function test_forget_sends_a_deletion(): void
    {
        $response = $this->boot();

        Cookie::forget('sid');

        self::assertStringContainsString('sid=;', $response->cookies[0]);
        self::assertStringContainsString('Max-Age=0', $response->cookies[0]);
    }

    public function test_forget_passes_the_scope_through(): void
    {
        $response = $this->boot();

        Cookie::forget('sid', '/admin', 'example.com');

        self::assertStringContainsString('Domain=example.com; Path=/admin', $response->cookies[0]);
    }

    /**
     * Silently dropping the cookie would look to the caller like the browser ignored it,
     * which is a far more expensive thing to debug than an exception here.
     */
    public function test_writing_outside_a_request_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cookie::init() has not run');

        Cookie::add(SetCookie::make('sid', 'abc'));
    }

    // ── make(): the request-aware constructor ─────────────────────────────────

    public function test_make_marks_a_cookie_secure_over_https(): void
    {
        $this->boot(scheme: 'https');

        self::assertTrue(Cookie::make('sid', 'abc')->isSecure());
    }

    /** Marked secure over plain HTTP, the browser would drop it without a word. */
    public function test_make_leaves_a_cookie_insecure_over_http(): void
    {
        $this->boot(scheme: 'http');

        self::assertFalse(Cookie::make('sid', 'abc')->isSecure());
    }

    public function test_defaults_are_applied_to_made_cookies(): void
    {
        $this->boot();
        Cookie::defaults(static fn(SetCookie $c) => $c->domain('example.com')->sameSite(SameSite::Strict));

        $cookie = Cookie::make('sid', 'abc');

        self::assertSame('example.com', $cookie->getDomain());
        self::assertSame(SameSite::Strict, $cookie->getSameSite());
    }

    /** The customiser runs after the scheme, so an application can overrule even that. */
    public function test_defaults_can_override_the_scheme_derived_secure(): void
    {
        $this->boot(scheme: 'http');
        Cookie::defaults(static fn(SetCookie $c) => $c->secure());

        self::assertTrue(Cookie::make('sid', 'abc')->isSecure(), 'behind a TLS-terminating proxy this is the truth');
    }

    public function test_defaults_do_not_touch_a_cookie_built_directly(): void
    {
        $this->boot();
        Cookie::defaults(static fn(SetCookie $c) => $c->domain('example.com'));

        self::assertNull(SetCookie::make('sid', 'abc')->getDomain(), 'SetCookie::make() stays pure');
    }

    public function test_defaults_survive_across_requests(): void
    {
        Cookie::defaults(static fn(SetCookie $c) => $c->domain('example.com'));

        $this->boot();   // a new request arrives
        self::assertSame('example.com', Cookie::make('a')->getDomain());
    }

    // ── Request isolation ─────────────────────────────────────────────────────

    public function test_a_new_request_replaces_the_previous_cookies(): void
    {
        $this->boot('a=1');
        $this->boot('b=2');

        self::assertSame(['b' => '2'], Cookie::all(), 'no leakage between requests');
    }
}
