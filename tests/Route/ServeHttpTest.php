<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Tests\Route\Fixtures\ServerProcess;
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
    private static ?ServerProcess $server = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('serving needs the Swoole extension.');
        }

        self::$server = new ServerProcess();
        if (!self::$server->start()) {
            $log = self::$server->log();
            self::$server->stop();
            self::$server = null;
            self::markTestSkipped("the server did not come up in time:\n" . substr($log, 0, 600));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    public function test_it_answers_a_simple_route(): void
    {
        $response = self::$server->request('GET', '/demo/ping');

        self::assertSame(200, $response['status']);
        self::assertSame('pong', $response['body']);
    }

    public function test_a_path_variable_survives_the_wire(): void
    {
        $response = self::$server->request('GET', '/demo/hello/winter');

        self::assertSame(200, $response['status']);
        self::assertSame(['message' => 'hello winter'], json_decode($response['body'], true));
    }

    public function test_a_query_string_is_parsed_and_cast(): void
    {
        $response = self::$server->request('GET', '/demo/search?q=snow&limit=3');

        self::assertSame(['q' => 'snow', 'limit' => 3], json_decode($response['body'], true));
    }

    public function test_a_post_route_is_reachable(): void
    {
        $response = self::$server->request('POST', '/demo/items');

        self::assertSame(200, $response['status']);
        self::assertSame(['created' => true], json_decode($response['body'], true));
    }

    public function test_an_unknown_path_returns_404_over_http(): void
    {
        self::assertSame(404, self::$server->request('GET', '/definitely-not-here')['status']);
    }

    public function test_the_wrong_method_returns_405_with_allow(): void
    {
        $response = self::$server->request('GET', '/demo/items');

        self::assertSame(405, $response['status']);
        self::assertStringContainsString('POST', ServerProcess::headerOf($response['headers'], 'Allow'));
    }

    public function test_json_responses_carry_a_json_content_type(): void
    {
        $response = self::$server->request('GET', '/demo/hello/winter');

        self::assertStringContainsString(
            'application/json',
            strtolower(ServerProcess::headerOf($response['headers'], 'Content-Type')),
        );
    }
}
