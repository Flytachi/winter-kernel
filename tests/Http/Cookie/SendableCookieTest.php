<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;
use Flytachi\Winter\Kernel\Http\Response\ResponseEntity;
use Flytachi\Winter\Kernel\Http\Response\ResponseFile;
use Flytachi\Winter\Kernel\Http\Response\ResponseStreamFile;
use Flytachi\Winter\Kernel\Http\Response\ResponseView;
use Flytachi\Winter\Kernel\Http\Response\Sendable;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every kernel response type carries cookies.
 *
 * Which `Sendable` a handler returns is a formatting decision — JSON, a rendered page, a
 * download — and it must not also decide whether a session can be opened. A login that
 * answers with a view had otherwise no declarative way to set its cookie.
 *
 * All four share {@see \Flytachi\Winter\Kernel\Http\Response\CarriesCookies}, so the same
 * assertions are run against each of them.
 */
final class SendableCookieTest extends TestCase
{
    private static string $views = '';
    private static string $file = '';
    private ?string $originalViewPath = null;

    protected function setUp(): void
    {
        // One directory for both fixtures: a template for the view, a file for the stream.
        self::$views = sys_get_temp_dir() . '/wk_sendable_' . getmypid();
        @mkdir(self::$views, 0777, true);
        file_put_contents(self::$views . '/hello.php', '<p>hello</p>');

        self::$file = self::$views . '/payload.txt';
        file_put_contents(self::$file, 'payload');

        $this->originalViewPath = ResponseView::getBasePath();
        ResponseView::setBasePath(self::$views);
    }

    protected function tearDown(): void
    {
        ResponseView::setBasePath($this->originalViewPath ?? '');

        foreach (glob(self::$views . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$views);
    }

    /** @return iterable<string, array{callable(): Sendable}> */
    public static function responses(): iterable
    {
        yield 'ResponseEntity' => [static fn(): Sendable => ResponseEntity::ok(['ok' => true])];

        yield 'ResponseFile' => [static fn(): Sendable => ResponseFile::txt('body', 'report.txt')];

        yield 'ResponseStreamFile' => [static fn(): Sendable => ResponseStreamFile::open(self::$file)];

        yield 'ResponseView' => [static fn(): Sendable => ResponseView::view('hello')];
    }

    private function send(Sendable $response): FakeResponse
    {
        $out = new FakeResponse();
        $response->send($out, new FakeRequest('GET', '/'));

        return $out;
    }

    /** @param callable(): Sendable $build */
    #[DataProvider('responses')]
    public function test_a_cookie_reaches_the_transport(callable $build): void
    {
        $response = $this->send($build()->cookie(SetCookie::make('sid', 'abc')));

        self::assertSame(['sid=abc; Path=/; HttpOnly; SameSite=Lax'], $response->cookies);
    }

    /** @param callable(): Sendable $build */
    #[DataProvider('responses')]
    public function test_several_cookies_keep_their_order(callable $build): void
    {
        $response = $this->send(
            $build()
                ->cookie(SetCookie::make('a', '1'))
                ->cookie(SetCookie::make('b', '2')),
        );

        self::assertCount(2, $response->cookies);
        self::assertStringStartsWith('a=1;', $response->cookies[0]);
        self::assertStringStartsWith('b=2;', $response->cookies[1]);
    }

    /** @param callable(): Sendable $build */
    #[DataProvider('responses')]
    public function test_a_response_without_cookies_sends_none(callable $build): void
    {
        self::assertSame([], $this->send($build())->cookies);
    }

    /** @param callable(): Sendable $build */
    #[DataProvider('responses')]
    public function test_the_builder_stays_chainable(callable $build): void
    {
        $response = $build()->cookie(SetCookie::make('a', '1'));

        self::assertInstanceOf(Sendable::class, $response);
        self::assertCount(1, $response->getCookies());
    }

    /** Cookies never land in the header map — it is keyed by name and would keep one. */
    #[DataProvider('responses')]
    public function test_cookies_do_not_leak_into_the_header_map(callable $build): void
    {
        $response = $this->send(
            $build()->cookie(SetCookie::make('a', '1'))->cookie(SetCookie::make('b', '2')),
        );

        self::assertArrayNotHasKey('Set-Cookie', $response->headers);
    }
}
