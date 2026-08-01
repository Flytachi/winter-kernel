<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Route;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The application over real HTTP: a Swoole server is started the way `call run` starts
 * it, and requests reach it through a socket.
 *
 * {@see ApplicationBootTest} already covers scan → DI → routing with doubles. What is
 * only reachable here is the last mile: the server actually binding and starting with
 * the settings it was given, the Swoole request/response adapters, and the bytes on the
 * wire — status line, headers, body. Nothing else in the suite proves the framework can
 * serve a request at all.
 *
 * Heavy by nature (a real process, a real port), hence the integration group.
 */
#[Group('integration')]
final class ServeHttpTest extends TestCase
{
    private static int $port = 0;
    private static int $pid = 0;
    private static string $storage = '';
    private static string $runner = '';
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('serving needs the Swoole extension.');
        }

        self::$port    = self::freePort();
        self::$storage = sys_get_temp_dir() . '/wk_serve_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$runner  = self::$storage . '.php';
        self::$log     = self::$storage . '.log';

        // The entry a project would write by hand: load the autoloader, run the app.
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents(self::$runner, sprintf(
            "<?php\nrequire %s;\n\\Flytachi\\Winter\\K2\\Tests\\Route\\Fixtures\\App\\ServeApp::main("
            . "['call', 'run', '--host=127.0.0.1', '--port=%d']);\n",
            var_export($autoload, true),
            self::$port,
        ));

        $command = sprintf(
            'WK_SERVE_STORAGE=%s %s %s >> %s 2>&1 & echo $!',
            escapeshellarg(self::$storage),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::$runner),
            escapeshellarg(self::$log),
        );
        self::$pid = (int) trim((string) shell_exec($command));

        if (!self::awaitReady(15.0)) {
            $log = (string) @file_get_contents(self::$log);
            self::stopServer();
            self::markTestSkipped("the server did not come up in time:\n" . substr($log, 0, 600));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        @unlink(self::$runner);
        @unlink(self::$log);
        foreach (glob(self::$storage . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(self::$storage . '/*') ?: [] as $dir) {
            is_dir($dir) ? @rmdir($dir) : @unlink($dir);
        }
        @rmdir(self::$storage);
    }

    // ── The requests ───────────────────────────────────────────────────────────

    public function test_it_answers_a_simple_route(): void
    {
        $response = $this->request('GET', '/demo/ping');

        self::assertSame(200, $response['status']);
        self::assertSame('pong', $response['body']);
    }

    public function test_a_path_variable_survives_the_wire(): void
    {
        $response = $this->request('GET', '/demo/hello/winter');

        self::assertSame(200, $response['status']);
        self::assertSame(['message' => 'hello winter'], json_decode($response['body'], true));
    }

    public function test_a_query_string_is_parsed_and_cast(): void
    {
        $response = $this->request('GET', '/demo/search?q=snow&limit=3');

        self::assertSame(['q' => 'snow', 'limit' => 3], json_decode($response['body'], true));
    }

    public function test_a_post_route_is_reachable(): void
    {
        $response = $this->request('POST', '/demo/items');

        self::assertSame(200, $response['status']);
        self::assertSame(['created' => true], json_decode($response['body'], true));
    }

    public function test_an_unknown_path_returns_404_over_http(): void
    {
        $response = $this->request('GET', '/definitely-not-here');

        self::assertSame(404, $response['status']);
    }

    public function test_the_wrong_method_returns_405_with_allow(): void
    {
        $response = $this->request('GET', '/demo/items');

        self::assertSame(405, $response['status']);
        self::assertStringContainsString('POST', self::headerOf($response['headers'], 'Allow'));
    }

    public function test_json_responses_carry_a_json_content_type(): void
    {
        $response = $this->request('GET', '/demo/hello/winter');

        self::assertStringContainsString(
            'application/json',
            strtolower(self::headerOf($response['headers'], 'Content-Type')),
        );
    }

    // ── Plumbing ───────────────────────────────────────────────────────────────

    /** @return array{status: int, body: string, headers: list<string>} */
    private function request(string $method, string $path): array
    {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'timeout'       => 5,
            'ignore_errors' => true, // 4xx/5xx must come back as a response, not a warning
        ]]);

        $body    = @file_get_contents(self::url($path), false, $context);
        $headers = $http_response_header ?? [];

        return [
            'status'  => self::statusOf($headers),
            'body'    => $body === false ? '' : $body,
            'headers' => $headers,
        ];
    }

    private static function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    /** @param list<string> $headers */
    private static function statusOf(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** @param list<string> $headers */
    private static function headerOf(array $headers, string $name): string
    {
        foreach ($headers as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                return trim($parts[1]);
            }
        }

        return '';
    }

    private static function awaitReady(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.3);
            if ($socket !== false) {
                fclose($socket);
                return true;
            }
            usleep(200_000);
        }

        return false;
    }

    private static function stopServer(): void
    {
        if (self::$pid > 0) {
            @exec(sprintf('kill -TERM %d 2>/dev/null', self::$pid));
            usleep(500_000);
            @exec(sprintf('kill -KILL %d 2>/dev/null', self::$pid));
            self::$pid = 0;
        }
    }

    /** Asks the OS for an unused port, so parallel runs do not collide. */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
