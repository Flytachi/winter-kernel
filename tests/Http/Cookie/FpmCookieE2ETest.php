<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use PHPUnit\Framework\TestCase;

/**
 * The FPM half, through a live SAPI.
 *
 * There is no other way to see it. Under CLI `header()` does nothing and
 * `headers_list()` stays empty, so the one line that matters — passing `false` as the
 * replace argument — cannot be observed by a unit test. Drop that argument and every
 * application sending more than one cookie loses all but the last, silently; this test
 * is what stands between that change and a release.
 *
 * The built-in server is used rather than php-fpm because it ships with PHP, runs the
 * same SAPI-level header machinery, and needs no configuration.
 */
final class FpmCookieE2ETest extends TestCase
{
    private string $docRoot = '';

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->docRoot = sys_get_temp_dir() . '/wk_cookie_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->docRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, SIGKILL);
            proc_close($this->server);
        }
        foreach (glob($this->docRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->docRoot);
    }

    public function test_several_cookies_all_reach_the_client(): void
    {
        $headers = $this->serve(<<<'PHP'
            $response = new FpmResponse();
            $response->cookie(SetCookie::make('a', '1'));
            $response->cookie(SetCookie::make('b', '2'));
            $response->cookie(SetCookie::make('c', '3')->secure());
            $response->end('ok');
            PHP);

        self::assertSame(
            [
                'a=1; Path=/; HttpOnly; SameSite=Lax',
                'b=2; Path=/; HttpOnly; SameSite=Lax',
                'c=3; Path=/; Secure; HttpOnly; SameSite=Lax',
            ],
            $headers,
            'header() replaces by name unless told not to — all three must survive',
        );
    }

    /**
     * The bytes are compared against a literal, not against SetCookie::toHeader(), so a
     * change in the serialiser cannot quietly move both sides of the assertion at once.
     */
    public function test_the_attributes_arrive_spelled_the_canonical_way(): void
    {
        $headers = $this->serve(<<<'PHP'
            new FpmResponse()->cookie(
                SetCookie::make('sid', 'a b')
                    ->expiresAt(1755600000)
                    ->domain('example.com')
                    ->path('/app')
                    ->secure()
                    ->sameSite(SameSite::None)
            );
            echo 'ok';
            PHP);

        self::assertCount(1, $headers);
        self::assertMatchesRegularExpression(
            '/^sid=a%20b; Expires=Tue, 19 Aug 2025 10:40:00 GMT; Max-Age=0; '
            . 'Domain=example\.com; Path=\/app; Secure; HttpOnly; SameSite=None$/',
            $headers[0],
        );
    }

    public function test_a_deletion_reaches_the_client_as_an_expired_cookie(): void
    {
        $headers = $this->serve(<<<'PHP'
            new FpmResponse()->cookie(SetCookie::forget('sid'));
            echo 'ok';
            PHP);

        self::assertSame(['sid=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/; HttpOnly; SameSite=Lax'], $headers);
    }

    /**
     * Runs a snippet through the built-in server and returns the Set-Cookie headers.
     *
     * @param string $body PHP statements; FpmResponse, SetCookie and SameSite are imported.
     * @return list<string>
     */
    private function serve(string $body): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        file_put_contents($this->docRoot . '/index.php', <<<PHP
            <?php
            require '{$autoload}';
            use Flytachi\\Winter\\Kernel\\Http\\Adapter\\FpmResponse;
            use Flytachi\\Winter\\Kernel\\Http\\Cookie\\SetCookie;
            use Flytachi\\Winter\\Kernel\\Http\\Cookie\\SameSite;
            {$body}
            PHP);

        $port = $this->freePort();
        $this->server = proc_open(
            [PHP_BINARY, '-n', '-S', "127.0.0.1:{$port}", '-t', $this->docRoot],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($this->server)) {
            self::markTestSkipped('Could not start the built-in server.');
        }

        $raw = $this->get("http://127.0.0.1:{$port}/index.php");

        $cookies = [];
        foreach ($raw as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookies[] = trim(substr($header, strlen('Set-Cookie:')));
            }
        }

        return $cookies;
    }

    /**
     * @return list<string> Raw response headers.
     */
    private function get(string $url): array
    {
        // The server needs a moment to bind; retry rather than sleep a fixed amount.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $headers = @get_headers($url);
            if ($headers !== false) {
                return $headers;
            }
            usleep(100_000);
        }

        self::markTestSkipped('The built-in server did not come up.');
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped('Could not reserve a port.');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
