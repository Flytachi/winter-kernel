<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Adapter\FpmRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `getPath()`, `getQueryString()`, `getUri()` and `getUrl()` answer the same under both
 * runtimes.
 *
 * They did not: `getUri()` was documented as `/users/42?page=1`, which FPM returned —
 * REQUEST_URI carries the query string — while Swoole returned the path alone, because
 * it publishes `request_uri` and `query_string` separately. The same request read
 * differently in logs and metrics depending on where the application ran.
 */
final class UrlPartsTest extends TestCase
{
    /**
     * path, query string as the server gives it, expected query, expected uri.
     *
     * @return array<string, array{string, ?string, ?string, string}>
     */
    public static function targets(): array
    {
        return [
            'path and query' => ['/layer/ussd', 'subscriber_number=%2B998&text_message=2', 'subscriber_number=%2B998&text_message=2', '/layer/ussd?subscriber_number=%2B998&text_message=2'],
            'no query'       => ['/users/42', null, null, '/users/42'],
            'empty query'    => ['/users/42', '', null, '/users/42'],
            'root'           => ['/', 'a=1', 'a=1', '/?a=1'],
        ];
    }

    private static function swoole(string $path, ?string $query, array $headers = []): HttpRequest
    {
        $raw = new \Swoole\Http\Request();
        $raw->server = ['request_uri' => $path] + ($query === null ? [] : ['query_string' => $query]);
        $raw->header = $headers + ['host' => 'api.example.uz'];
        return new SwooleRequest($raw);
    }

    /** Runs $fn against an FPM request built from the given $_SERVER values. */
    private static function fpm(array $server, callable $fn): void
    {
        $original = $_SERVER;
        foreach (['REQUEST_URI', 'QUERY_STRING', 'HTTP_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTPS', 'SERVER_PORT'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER = $server + ['HTTP_HOST' => 'api.example.uz'] + $_SERVER;
        try {
            $fn(new FpmRequest());
        } finally {
            $_SERVER = $original;
        }
    }

    #[DataProvider('targets')]
    public function test_swoole(string $path, ?string $rawQuery, ?string $query, string $uri): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }

        $request = self::swoole($path, $rawQuery);

        self::assertSame($path, $request->getPath());
        self::assertSame($query, $request->getQueryString());
        self::assertSame($uri, $request->getUri());
        self::assertSame('http://api.example.uz' . $uri, $request->getUrl());
    }

    #[DataProvider('targets')]
    public function test_fpm(string $path, ?string $rawQuery, ?string $query, string $uri): void
    {
        // FPM hands over REQUEST_URI with the query (and a bare "?" when it is empty).
        $requestUri = $rawQuery === null ? $path : $path . '?' . $rawQuery;
        $server = ['REQUEST_URI' => $requestUri] + ($rawQuery === null ? [] : ['QUERY_STRING' => $rawQuery]);

        self::fpm($server, function (HttpRequest $request) use ($path, $query, $uri): void {
            self::assertSame($path, $request->getPath());
            self::assertSame($query, $request->getQueryString());
            self::assertSame($uri, $request->getUri());
            self::assertSame('http://api.example.uz' . $uri, $request->getUrl());
        });
    }

    public function test_fpm_reads_the_query_from_the_uri_when_query_string_is_not_set(): void
    {
        self::fpm(['REQUEST_URI' => '/search?q=winter'], function (HttpRequest $request): void {
            self::assertSame('/search', $request->getPath());
            self::assertSame('q=winter', $request->getQueryString());
        });
    }

    public function test_the_url_carries_the_scheme_the_proxy_reports(): void
    {
        self::fpm(
            ['REQUEST_URI' => '/a?b=1', 'QUERY_STRING' => 'b=1', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            fn(HttpRequest $request) => self::assertSame('https://api.example.uz/a?b=1', $request->getUrl()),
        );

        if (extension_loaded('swoole')) {
            $request = self::swoole('/a', 'b=1', ['x-forwarded-proto' => 'https']);
            self::assertSame('https://api.example.uz/a?b=1', $request->getUrl());
        }
    }
}
