<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Http\Response\ResponseEntity;
use Flytachi\Winter\Kernel\Route\RequestWatchdog;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * What the client is actually told when a request runs out of time.
 *
 * The first version got this wrong in a way only a live server showed. `invoke()` has a
 * `catch` of its own, so it answered the raw `Swoole\Coroutine\CanceledException` —
 * code 0, empty message — as **500**, and logged it as a server fault. The outer 504
 * arrived afterwards: too late to change the response, early enough to add a second log
 * line. The client saw `500 {"code":0,"message":""}` for a plain timeout.
 *
 * The fix moved the translation to where the response is built, so there is one answer
 * and one reason for it. These tests hold that.
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
final class RouterTimeoutResponseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The watchdog is a Swoole timer.');
        }
        RequestWatchdog::disable();
    }

    protected function tearDown(): void
    {
        RequestWatchdog::disable();
        \Swoole\Timer::clearAll();
    }

    /** Dispatches one request under a deadline and returns what the client would get. */
    private function send(Router $router, float $deadline, string $uri = '/'): FakeResponse
    {
        $response = new FakeResponse();

        \Swoole\Coroutine\run(static function () use ($router, $response, $deadline, $uri): void {
            RequestWatchdog::enable($deadline);

            Coroutine::create(static function () use ($router, $response, $uri): void {
                $router->handle(new FakeRequest('GET', $uri), $response);
            });

            Coroutine::sleep($deadline + 0.5);
            RequestWatchdog::disable();
        });

        return $response;
    }

    /** A handler that waits too long: cancelled, and the client is told why. */
    public function test_a_timed_out_request_answers_504(): void
    {
        $router = new Router()->get('/', static function () {
            Coroutine::sleep(5);
            return ResponseEntity::ok('never reached');
        });

        $response = $this->send($router, 0.15);

        self::assertSame(504, $response->status);
        self::assertSame(
            ['code' => 504, 'message' => 'Gateway Timeout'],
            $response->json(),
            'not the coroutine exception\'s empty message and code 0',
        );
    }

    /**
     * A handler that catches everything reaches its end with a result built from queries
     * that never ran. That result must not be what the client receives.
     */
    public function test_a_handler_that_swallows_the_cancellation_still_answers_504(): void
    {
        $router = new Router()->get('/', static function () {
            for ($i = 0; $i < 4; $i++) {
                try {
                    Coroutine::sleep(0.5);
                } catch (\Throwable) {
                    // swallowed on purpose
                }
            }
            return ResponseEntity::ok(['report' => 'built from nothing']);
        });

        $response = $this->send($router, 0.15);

        self::assertSame(504, $response->status);
        self::assertSame(['code' => 504, 'message' => 'Gateway Timeout'], $response->json());
    }

    /** The response is written once — a second write would be the old double-send. */
    public function test_the_response_is_sent_exactly_once(): void
    {
        $router = new Router()->get('/', static function () {
            Coroutine::sleep(5);
            return ResponseEntity::ok('never reached');
        });

        $response = $this->send($router, 0.15);

        self::assertTrue($response->ended);
        self::assertSame(504, $response->status, 'a later write would have overwritten this');
    }

    public function test_a_request_within_its_deadline_is_untouched(): void
    {
        $router = new Router()->get('/', static function () {
            Coroutine::sleep(0.02);
            return ResponseEntity::ok('done');
        });

        $response = $this->send($router, 1.0);

        self::assertSame(200, $response->status);
        self::assertSame('done', $response->body, 'a plain string is sent as-is, not wrapped');
    }

    /**
     * A real failure on a request that also timed out must still name the real failure —
     * the timeout wraps it as the cause rather than replacing it.
     */
    public function test_an_error_that_is_not_a_timeout_keeps_its_own_status(): void
    {
        $router = new Router()->get('/', static function () {
            throw new \RuntimeException('something else went wrong');
        });

        $response = $this->send($router, 1.0);

        self::assertNotSame(504, $response->status);
    }
}
