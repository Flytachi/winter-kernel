<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Route\Dispatcher;
use Flytachi\Winter\Kernel\Route\Route;
use Flytachi\Winter\Kernel\Route\RouteResult;
use PHPUnit\Framework\TestCase;

/**
 * Precedence between dynamic routes whose patterns overlap.
 *
 * Two dynamic paths can accept the same URI — "/docs/{id}/positions/{productId}"
 * and "/docs/{id}/positions/delete" both take "/docs/5/positions/delete". Which one
 * wins must follow how specific the pattern is, never the order the routes happened
 * to be registered in, so moving a method inside a controller cannot silently change
 * where a request lands.
 */
final class DispatcherPrecedenceTest extends TestCase
{
    /** @param list<Route> $routes */
    private function dispatch(array $routes, string $method, string $uri): RouteResult
    {
        return new Dispatcher([], $routes)->dispatch($method, $uri);
    }

    /** @return list<Route> Placeholder route registered before the literal one. */
    private function positionRoutes(): array
    {
        return [
            new Route('PUT', '/docs/{documentId}/positions/{productId}', 'update'),
            new Route('DELETE', '/docs/{documentId}/positions/{productId}', 'remove'),
            new Route('GET', '/docs/{documentId}/positions/{productId}', 'show'),
            new Route('POST', '/docs/{documentId}/positions/delete', 'bulkDelete'),
        ];
    }

    // ── Literal beats placeholder ─────────────────────────────────────────────

    public function test_a_literal_segment_wins_over_a_placeholder_registered_earlier(): void
    {
        $result = $this->dispatch($this->positionRoutes(), 'POST', '/docs/5/positions/delete');

        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertSame('bulkDelete', $result->handler);
        self::assertSame(['documentId' => '5'], $result->params);
    }

    public function test_a_literal_segment_wins_even_when_both_routes_share_the_method(): void
    {
        $routes = [
            new Route('POST', '/docs/{documentId}/positions/{productId}', 'replaceOne'),
            new Route('POST', '/docs/{documentId}/positions/delete', 'bulkDelete'),
        ];

        $result = $this->dispatch($routes, 'POST', '/docs/5/positions/delete');

        self::assertSame('bulkDelete', $result->handler, 'the placeholder must not swallow a literal segment');
    }

    public function test_registration_order_does_not_change_the_winner(): void
    {
        $reversed = array_reverse($this->positionRoutes());

        $result = $this->dispatch($reversed, 'POST', '/docs/5/positions/delete');

        self::assertSame('bulkDelete', $result->handler);
    }

    public function test_the_placeholder_route_still_serves_an_ordinary_segment(): void
    {
        $result = $this->dispatch($this->positionRoutes(), 'PUT', '/docs/5/positions/77');

        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertSame('update', $result->handler);
        self::assertSame(['documentId' => '5', 'productId' => '77'], $result->params);
    }

    public function test_a_constrained_placeholder_wins_over_a_free_one(): void
    {
        $routes = [
            new Route('GET', '/items/{slug}', 'bySlug'),
            new Route('GET', '/items/{id:\d+}', 'byId'),
        ];

        self::assertSame('byId', $this->dispatch($routes, 'GET', '/items/42')->handler);
        self::assertSame('bySlug', $this->dispatch($routes, 'GET', '/items/lamp')->handler);
    }

    public function test_a_catch_all_route_yields_to_a_more_specific_one(): void
    {
        $routes = [
            new Route('GET', '/files/{path:.*}', 'serveAny'),
            new Route('GET', '/files/{id:\d+}', 'serveOne'),
        ];

        self::assertSame('serveOne', $this->dispatch($routes, 'GET', '/files/7')->handler);
        self::assertSame('serveAny', $this->dispatch($routes, 'GET', '/files/a/b/c.txt')->handler);
    }

    public function test_precedence_holds_across_chunk_boundaries(): void
    {
        $routes = [new Route('POST', '/docs/{documentId}/positions/{productId}', 'replaceOne')];
        for ($i = 0; $i < 60; $i++) {
            $routes[] = new Route('GET', "/filler{$i}/{id}", "filler{$i}");
        }
        $routes[] = new Route('POST', '/docs/{documentId}/positions/delete', 'bulkDelete');

        $result = $this->dispatch($routes, 'POST', '/docs/5/positions/delete');

        self::assertSame('bulkDelete', $result->handler);
    }

    // ── Fallback within an overlapping set ────────────────────────────────────

    public function test_a_less_specific_route_serves_a_method_the_winner_lacks(): void
    {
        $result = $this->dispatch($this->positionRoutes(), 'GET', '/docs/5/positions/delete');

        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertSame('show', $result->handler);
        self::assertSame(['documentId' => '5', 'productId' => 'delete'], $result->params);
    }

    public function test_allowed_methods_cover_every_route_matching_the_uri(): void
    {
        $routes = [
            new Route('PUT', '/docs/{documentId}/positions/{productId}', 'update'),
            new Route('DELETE', '/docs/{documentId}/positions/{productId}', 'remove'),
            new Route('POST', '/docs/{documentId}/positions/delete', 'bulkDelete'),
        ];

        $result = $this->dispatch($routes, 'PATCH', '/docs/5/positions/delete');

        self::assertSame(RouteResult::METHOD_NOT_ALLOWED, $result->status);
        $allowed = $result->allowedMethods;
        sort($allowed);
        self::assertSame(['DELETE', 'POST', 'PUT'], $allowed);
    }

    public function test_an_unmatched_uri_is_still_not_found(): void
    {
        $result = $this->dispatch($this->positionRoutes(), 'POST', '/docs/5/lines/delete');

        self::assertSame(RouteResult::NOT_FOUND, $result->status);
    }
}
