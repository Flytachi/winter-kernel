<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use Flytachi\Winter\Kernel\Http\Adapter\SwooleResponse;
use Flytachi\Winter\Kernel\Http\Cookie\Cookie;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;
use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Tests\Http\Fixtures\OriginProbeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The Swoole half: how the cookie reaches the wire, and that two requests sharing a
 * worker never see each other's.
 *
 * The adapter deliberately does not call Swoole's own `cookie()`. That method spells the
 * attributes its own way — `expires=`, `path=`, `secure` in lower case — and encodes the
 * value with `+`, none of which FPM would reproduce. The header is built by
 * {@see SetCookie} and handed over verbatim instead, which is what these assertions pin.
 *
 * Each test runs in its own process on purpose. Xdebug's function observers do not survive
 * coroutine stacks: once a child coroutine has suspended and resumed, the interpreter
 * segfaults in `xdebug_execute_user_code_end` at request shutdown — after the tests
 * themselves have passed, so the report says OK and the exit code says 139. Every
 * `xdebug.mode` does it, `coverage` included; the alternative is running the suite under
 * `XDEBUG_MODE=off`, which nobody remembers to do. Here the crash lands in a child whose
 * result is already out, and the run stays green wherever Xdebug happens to be loaded.
 */
#[RunTestsInSeparateProcesses]
final class CookieSwooleTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }
        Cookie::clear();
    }

    protected function tearDown(): void
    {
        Cookie::clear();
    }

    private function spy(): SpyingSwooleResponse
    {
        return new ReflectionClass(SpyingSwooleResponse::class)->newInstanceWithoutConstructor();
    }

    public function test_the_cookie_goes_out_as_the_header_we_built(): void
    {
        $raw = $this->spy();

        new SwooleResponse($raw)->cookie(SetCookie::make('sid', 'abc'));

        self::assertSame(['Set-Cookie' => ['sid=abc; Path=/; HttpOnly; SameSite=Lax']], $raw->written);
    }

    /** Swoole's own cookie() would have written `path=/` and `secure` in lower case. */
    public function test_attribute_spelling_is_ours_not_swooles(): void
    {
        $raw = $this->spy();

        new SwooleResponse($raw)->cookie(SetCookie::make('sid', 'abc')->secure());

        self::assertStringContainsString('Path=/; Secure; HttpOnly', $raw->written['Set-Cookie'][0]);
        self::assertSame([], $raw->cookieCalls, 'the native cookie() API is not used');
    }

    /** Swoole's own cookie() would have encoded the space as `+`. */
    public function test_the_value_is_encoded_the_same_way_as_under_fpm(): void
    {
        $raw = $this->spy();

        new SwooleResponse($raw)->cookie(SetCookie::make('t', 'a b'));

        self::assertStringStartsWith('t=a%20b;', $raw->written['Set-Cookie'][0]);
    }

    /**
     * A repeated header in Swoole is one call carrying every value: a later call replaces
     * the whole set rather than appending to it, so the adapter re-sends the full list.
     */
    public function test_every_cookie_survives_the_next_one(): void
    {
        $raw      = $this->spy();
        $response = new SwooleResponse($raw);

        $response->cookie(SetCookie::make('a', '1'));
        $response->cookie(SetCookie::make('b', '2'));

        self::assertCount(2, $raw->written['Set-Cookie']);
        self::assertStringStartsWith('a=1;', $raw->written['Set-Cookie'][0]);
        self::assertStringStartsWith('b=2;', $raw->written['Set-Cookie'][1]);
    }

    // ── Coroutine isolation ───────────────────────────────────────────────────

    /**
     * Two requests are two coroutines in one worker. A cookie read in one must never be
     * the cookie another request sent — the failure mode being one user served another
     * user's session.
     */
    public function test_each_coroutine_sees_only_its_own_cookies(): void
    {
        $seen = [];

        \Swoole\Coroutine\run(static function () use (&$seen): void {
            foreach (['alice' => 'a-token', 'bob' => 'b-token'] as $user => $token) {
                \Swoole\Coroutine::create(static function () use ($user, $token, &$seen): void {
                    $request = new OriginProbeRequest(headers: ['Cookie' => "sid={$token}"]);
                    Header::init($request);
                    Cookie::init($request, new FakeResponse());

                    // Yield in the middle, so the other request definitely runs in between.
                    \Swoole\Coroutine::sleep(0.01);

                    $seen[$user] = Cookie::get('sid');
                });
            }
        });

        self::assertSame(['alice' => 'a-token', 'bob' => 'b-token'], $seen);
    }

    public function test_a_cookie_written_in_one_coroutine_reaches_only_its_response(): void
    {
        $responses = [];

        \Swoole\Coroutine\run(static function () use (&$responses): void {
            foreach (['a', 'b'] as $name) {
                \Swoole\Coroutine::create(static function () use ($name, &$responses): void {
                    $request  = new OriginProbeRequest();
                    $response = new FakeResponse();
                    Header::init($request);
                    Cookie::init($request, $response);

                    \Swoole\Coroutine::sleep(0.01);
                    Cookie::add(SetCookie::make($name, '1'));

                    $responses[$name] = $response;
                });
            }
        });

        self::assertCount(1, $responses['a']->cookies);
        self::assertStringStartsWith('a=1;', $responses['a']->cookies[0]);
        self::assertStringStartsWith('b=1;', $responses['b']->cookies[0]);
    }
}
