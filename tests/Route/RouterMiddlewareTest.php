<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FirstMiddleware;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\RecordingMiddleware;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\SecondMiddleware;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Middleware around a dispatched route.
 *
 * The nesting order is the contract: `before()` runs outward-in, `after()` unwinds
 * inward-out, so a middleware that opens something in `before()` can close it in
 * `after()`. Reversing one of the two would still "work" for a single middleware and
 * silently corrupt every pair beyond that — hence the explicit trace.
 */
final class RouterMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Container::init();
        RecordingMiddleware::reset();
    }

    /** @param list<array{class: class-string, args: array}> $middlewares */
    private function dispatch(array $middlewares, mixed $handler = null): FakeResponse
    {
        $router = new Router();
        $router->add('GET', '/m', $handler ?? static fn(): string => 'body', $middlewares);

        $response = new FakeResponse();
        $router->handle(new FakeRequest('GET', '/m'), $response);

        return $response;
    }

    private static function def(string $class): array
    {
        return ['class' => $class, 'args' => []];
    }

    public function test_before_runs_in_order_and_after_unwinds_in_reverse(): void
    {
        $this->dispatch([self::def(FirstMiddleware::class), self::def(SecondMiddleware::class)]);

        self::assertSame(
            ['before:first', 'before:second', 'after:second', 'after:first'],
            RecordingMiddleware::$trace,
        );
    }

    public function test_after_can_transform_the_result_on_the_way_out(): void
    {
        $response = $this->dispatch([self::def(FirstMiddleware::class), self::def(SecondMiddleware::class)]);

        // 'body' → innermost (second) wraps first, then first — mirroring the unwind.
        self::assertSame('body|second|first', $response->body);
    }

    public function test_a_single_middleware_still_runs_both_hooks(): void
    {
        $response = $this->dispatch([self::def(FirstMiddleware::class)]);

        self::assertSame(['before:first', 'after:first'], RecordingMiddleware::$trace);
        self::assertSame('body|first', $response->body);
    }

    public function test_a_route_without_middleware_is_untouched(): void
    {
        $response = $this->dispatch([]);

        self::assertSame([], RecordingMiddleware::$trace);
        self::assertSame('body', $response->body);
    }

    public function test_a_failing_handler_skips_the_after_hooks(): void
    {
        // after() is the unwind of a *successful* call; on a throw the error path takes
        // over, so a middleware must not assume after() always follows before().
        $response = $this->dispatch(
            [self::def(FirstMiddleware::class)],
            static function (): never {
                throw new RuntimeException('kaboom');
            },
        );

        self::assertSame(['before:first'], RecordingMiddleware::$trace);
        self::assertSame(500, $response->status);
    }
}
