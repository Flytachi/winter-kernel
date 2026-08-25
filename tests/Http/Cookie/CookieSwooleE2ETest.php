<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use PHPUnit\Framework\TestCase;

/**
 * The request side under Swoole, through a live server.
 *
 * There is no other way to see it. `Swoole\Http\Request` cannot be constructed, so a unit
 * test builds one with `newInstanceWithoutConstructor()` and fills `header` by hand — free
 * to describe a request the runtime never produces. It did exactly that: with Swoole's own
 * cookie parsing left on (its default) the raw `Cookie:` header never reaches
 * `$request->header` at all, so the adapter read an empty string and every cookie under
 * Swoole came back absent, while the unit test — holding a header array it had written
 * itself — stayed green.
 *
 * The server runs in a child process: `Swoole\Http\Server::start()` never returns, and the
 * extension refuses to run a server inside a process that already has one. It is configured
 * through {@see \Flytachi\Winter\Kernel\App\Config\ServerSettings} rather than a hand-written
 * option array, so what these tests pin is the chain an application actually boots —
 * settings → server → adapter — and not a set of options invented here.
 */
final class CookieSwooleE2ETest extends TestCase
{
    private string $dir = '';

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The Swoole adapter needs the extension.');
        }
        $this->dir = sys_get_temp_dir() . '/wk_swoole_cookie_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            // SIGTERM, not SIGKILL: Swoole's master forks a manager and its workers, and
            // only a signal it can handle takes them down with it. Killed outright, the
            // master dies and the rest are reparented to init — still bound, still holding
            // the inherited descriptors, and outliving the run.
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * The whole point: a cookie sent by a client is readable on the other side. Fails on
     * an adapter that trusts `$request->header['cookie']` under Swoole's default parsing.
     */
    public function test_a_cookie_survives_the_swoole_runtime(): void
    {
        self::assertSame(['sid' => 'abc123'], $this->serve('sid=abc123'));
    }

    /**
     * The parity CookieParser exists for, measured against the runtime rather than assumed:
     * Swoole's own parser renames `my.sid` to `my_sid` and keeps the last of two same-named
     * cookies. Neither may be what the application sees.
     */
    public function test_names_and_duplicates_mean_what_they_mean_under_fpm(): void
    {
        self::assertSame(
            ['sid' => 'abc', 'my.sid' => '1'],
            $this->serve('sid=abc; my.sid=1; my.sid=2'),
            'the dot must survive and the first duplicate must win',
        );
    }

    /** Values arrive decoded, the same way `$_COOKIE` would hand them over. */
    public function test_the_value_is_decoded(): void
    {
        self::assertSame(['t' => 'a b'], $this->serve('t=a%20b'));
    }

    /**
     * An application is free to switch Swoole's parsing back on — it is a raw option like
     * any other. The raw header is then gone inside the extension and cannot be rebuilt, so
     * the adapter falls back to Swoole's own map: mangled names, last duplicate wins. A
     * degraded answer on purpose; silently reporting no cookies at all is the worse one.
     */
    public function test_swooles_own_parsing_degrades_rather_than_empties(): void
    {
        self::assertSame(
            ['sid' => 'abc', 'my_sid' => '2'],
            $this->serve('sid=abc; my.sid=1; my.sid=2', "->set('http_parse_cookie', true)"),
        );
    }

    /**
     * Runs a request through a live Swoole server and returns what the adapter made of its
     * cookies.
     *
     * @param string $cookieHeader Raw value of the `Cookie` header to send.
     * @param string $override PHP appended to the ServerSettings expression, e.g. a ->set().
     * @return array<string, string>
     */
    private function serve(string $cookieHeader, string $override = ''): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $port     = $this->freePort();
        $script   = $this->dir . '/server.php';

        file_put_contents($script, <<<PHP
            <?php
            require '{$autoload}';

            use Flytachi\\Winter\\Kernel\\App\\Config\\ServerSettings;
            use Flytachi\\Winter\\Kernel\\Http\\Adapter\\SwooleRequest;

            \$settings = ServerSettings::fromEnv('127.0.0.1', {$port}){$override};
            \$server = new \\Swoole\\Http\\Server('127.0.0.1', {$port});
            // One worker and a quiet log are the harness; everything else is the
            // application's own configuration, which is what is under test.
            \$server->set(['worker_num' => 1, 'log_level' => 5] + \$settings->toArray());
            \$server->on('request', function (\\Swoole\\Http\\Request \$req, \\Swoole\\Http\\Response \$res) use (\$server) {
                \$res->header('Content-Type', 'application/json');
                \$res->end(json_encode(new SwooleRequest(\$req)->getCookies()));
                // One request is the whole test. Shutting down here means the child cannot
                // outlive it even if the test aborts before tearDown.
                \$server->shutdown();
            });
            \$server->start();
            PHP);

        // The default ini is kept — `-n` would drop the Swoole extension with it. Xdebug is
        // switched off explicitly: its function observers do not survive Swoole's coroutine
        // stacks and the child segfaults at shutdown.
        $this->server = proc_open(
            [PHP_BINARY, '-d', 'xdebug.mode=off', $script],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($this->server)) {
            self::markTestSkipped('Could not start the Swoole server.');
        }

        $body = $this->get("http://127.0.0.1:{$port}/", $cookieHeader);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, "the server answered with something that is not JSON: {$body}");

        return $decoded;
    }

    private function get(string $url, string $cookieHeader): string
    {
        $context = stream_context_create(['http' => [
            'header'         => "Cookie: {$cookieHeader}\r\n",
            'ignore_errors'  => true,
            'timeout'        => 5,
        ]]);

        // The server needs a moment to bind; retry rather than sleep a fixed amount.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $body = @file_get_contents($url, false, $context);
            if ($body !== false) {
                return $body;
            }
            usleep(100_000);
        }

        self::markTestSkipped('The Swoole server did not come up.');
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
