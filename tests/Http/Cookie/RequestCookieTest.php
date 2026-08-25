<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use Flytachi\Winter\Kernel\Http\Adapter\FpmRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The request side of the contract, on both adapters.
 *
 * `getCookie()` / `getCookies()` mirror `getHeader()` / `getHeaders()` — a cookie is read
 * the same way a header is. The point of running the same assertions against both
 * adapters is that a request must not mean different things in the two runtimes, which is
 * exactly what taking `$_COOKIE` on one side and Swoole's parsing on the other would have
 * produced.
 *
 * What this file cannot show is that the header it hands the Swoole adapter is a header
 * the runtime actually produces: `Swoole\Http\Request` has no constructor, so the fixture
 * fills `header` by hand and is free to invent. It once invented a `cookie` key the
 * extension drops before the application ever sees it, and these tests stayed green while
 * no cookie under Swoole was readable at all. {@see CookieSwooleE2ETest} is what pins that
 * half, through a live server; this file pins the parsing given the header.
 */
final class RequestCookieTest extends TestCase
{
    private ?string $originalCookieHeader = null;

    protected function setUp(): void
    {
        $this->originalCookieHeader = $_SERVER['HTTP_COOKIE'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalCookieHeader === null) {
            unset($_SERVER['HTTP_COOKIE']);
        } else {
            $_SERVER['HTTP_COOKIE'] = $this->originalCookieHeader;
        }
    }

    /**
     * Both adapters, built around the same raw header.
     *
     * @return iterable<string, array{callable(string): HttpRequest}>
     */
    public static function adapters(): iterable
    {
        yield 'fpm' => [static function (string $header): HttpRequest {
            // No getallheaders() under CLI, so FpmRequest reads $_SERVER — the same path
            // it takes on any SAPI that lacks the function.
            $_SERVER['HTTP_COOKIE'] = $header;
            return new FpmRequest();
        }];

        yield 'swoole' => [static function (string $header): HttpRequest {
            if (!extension_loaded('swoole')) {
                self::markTestSkipped('The Swoole adapter needs the extension.');
            }
            $raw = new ReflectionClass(\Swoole\Http\Request::class)->newInstanceWithoutConstructor();
            $raw->header = ['cookie' => $header];

            return new SwooleRequest($raw);
        }];
    }

    /** @param callable(string): HttpRequest $build */
    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function test_a_single_cookie_is_readable(callable $build): void
    {
        $request = $build('sid=abc123');

        self::assertSame('abc123', $request->getCookie('sid'));
        self::assertSame(['sid' => 'abc123'], $request->getCookies());
    }

    /** @param callable(string): HttpRequest $build */
    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function test_an_absent_cookie_is_null(callable $build): void
    {
        self::assertNull($build('sid=abc123')->getCookie('other'));
    }

    /** @param callable(string): HttpRequest $build */
    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function test_no_header_is_an_empty_map(callable $build): void
    {
        $request = $build('');

        self::assertSame([], $request->getCookies());
        self::assertNull($request->getCookie('sid'));
    }

    /**
     * The divergence this whole path exists to avoid: `$_COOKIE` would answer
     * `["my_sid", "ok"]` here, Swoole would not.
     *
     * @param callable(string): HttpRequest $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function test_both_runtimes_report_the_same_awkward_names(callable $build): void
    {
        $request = $build('my.sid=1; my sid=2; ok=3');

        self::assertSame(['my.sid' => '1', 'my sid' => '2', 'ok' => '3'], $request->getCookies());
        self::assertSame('1', $request->getCookie('my.sid'));
    }

    /** @param callable(string): HttpRequest $build */
    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function test_values_arrive_decoded(callable $build): void
    {
        self::assertSame('a b/c', $build('t=a%20b%2Fc')->getCookie('t'));
    }

    /**
     * Reading the same cookie twice must not parse the header twice; the map is also
     * expected to be the same one, so nothing downstream sees it change mid-request.
     *
     * @param callable(string): HttpRequest $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function test_the_header_is_parsed_once(callable $build): void
    {
        $request = $build('a=1; b=2');

        self::assertSame($request->getCookies(), $request->getCookies());
        self::assertSame('1', $request->getCookie('a'));
        self::assertSame('2', $request->getCookie('b'));
    }

    /**
     * The other shape the runtime produces: an application that switched `http_parse_cookie`
     * back on gets no raw header and Swoole's own map instead. The adapter hands that over
     * as it is — mangled names and last-duplicate-wins included, which is why the expected
     * value here is spelled the way Swoole spells it and not the way the rest of this file
     * does. Degraded on purpose; answering "no cookies" would be worse.
     */
    public function test_swoole_falls_back_to_its_own_map_when_the_header_was_consumed(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }
        $raw = new ReflectionClass(\Swoole\Http\Request::class)->newInstanceWithoutConstructor();
        $raw->header = ['host' => 'localhost'];
        $raw->cookie = ['sid' => 'abc', 'my_sid' => '2'];

        $request = new SwooleRequest($raw);

        self::assertSame(['sid' => 'abc', 'my_sid' => '2'], $request->getCookies());
        self::assertSame('2', $request->getCookie('my_sid'));
    }

    /** The naming this rename was about: cookies read like headers. */
    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function test_the_singular_and_plural_agree(callable $build): void
    {
        $request = $build('sid=abc; theme=dark');

        foreach ($request->getCookies() as $name => $value) {
            self::assertSame($value, $request->getCookie($name));
        }
    }
}
