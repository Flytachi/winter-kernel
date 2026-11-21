<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestBody;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestHeader;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\Kernel\Http\Request\Validation\Valid;
use Flytachi\Winter\Kernel\Http\Stereotype\Controller;
use Flytachi\Winter\Kernel\Route\Annotation\GetMapping;
use Flytachi\Winter\Kernel\Route\Annotation\RequestMapping;
use Flytachi\Winter\Kernel\Route\Collector\MappingCollector;
use Flytachi\Winter\Kernel\Route\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A controller parameter without a source attribute is looked up among the path variables
 * only: `?string $q = null` under `/search` was null whatever `?q=` said, `int $page = 1`
 * was always 1, a required `string $q` failed every request with 500 — and the route was
 * registered and listed as if nothing were wrong. The route table now says it at start.
 */
final class UnboundParameterTest extends TestCase
{
    public function test_a_scalar_no_request_fills_is_reported_with_what_it_always_is(): void
    {
        $warnings = $this->warnings(UnboundSearchController::class);

        self::assertCount(4, $warnings);
        self::assertSame(
            'Route GET /search (' . UnboundSearchController::class . '::search): $q is never taken from the '
            . 'request — it is not a path variable, and a query parameter needs #[RequestParam]. It is always null.',
            $warnings[0],
        );
        self::assertStringContainsString('$page is never taken', $warnings[1]);
        self::assertStringEndsWith('It is always its default value.', $warnings[1]);
        self::assertStringContainsString('$sort is never taken', $warnings[2]);
        self::assertStringContainsString('Route GET /strict', $warnings[3]);
        self::assertStringEndsWith('Every request to it fails with 500.', $warnings[3]);
    }

    public function test_what_has_a_source_or_is_no_scalar_is_left_alone(): void
    {
        self::assertSame([], $this->warnings(UnboundBoundController::class));
    }

    public function test_a_path_variable_of_the_controller_prefix_counts(): void
    {
        self::assertSame([], $this->warnings(UnboundShopController::class));
    }

    public function test_a_mapping_of_every_method_is_reported_once(): void
    {
        $warnings = $this->warnings(UnboundAnyController::class);

        self::assertCount(1, $warnings);
        self::assertStringStartsWith('Route ANY /any (', $warnings[0]);
    }

    public function test_the_warnings_follow_the_route_filter(): void
    {
        $router = $this->router(UnboundSearchController::class);

        self::assertCount(3, $router->unboundWarnings('search'));
        self::assertCount(1, $router->unboundWarnings('strict'));
        self::assertSame([], $router->unboundWarnings('nothing'));
    }

    public function test_the_routes_themselves_are_registered_as_before(): void
    {
        $paths = array_column($this->router(UnboundSearchController::class)->getRoutesSummary(), 'path');

        self::assertSame(['/search', '/strict'], $paths);
    }

    /** @return list<string> */
    private function warnings(string $controller): array
    {
        return $this->router($controller)->unboundWarnings();
    }

    private function router(string $controller): Router
    {
        $router = new Router();
        new MappingCollector($router)->collect($controller, new ReflectionClass($controller));

        return $router;
    }
}

enum UnboundSort: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}

final class UnboundSearchController extends Controller
{
    #[GetMapping('/search')]
    public function search(?string $q = null, int $page = 1, UnboundSort $sort = UnboundSort::Asc): array
    {
        return [$q, $page, $sort];
    }

    #[GetMapping('/strict')]
    public function strict(string $q): array
    {
        return [$q];
    }
}

final class UnboundFilter
{
    public string $status = '';
}

final class UnboundBoundController extends Controller
{
    #[GetMapping('/users/{id}')]
    public function user(int $id, #[RequestParam] ?string $fields = null): array
    {
        return [$id, $fields];
    }

    #[GetMapping('/orders/{orderId:\d+}')]
    public function order(
        int $orderId,
        #[PathVariable('orderId')] int $same,
        #[RequestHeader] ?string $acceptLanguage = null,
        #[RequestParam] #[Valid] int $page = 1,
    ): array {
        return [$orderId, $same, $acceptLanguage, $page];
    }

    #[GetMapping('/raw')]
    public function raw(HttpRequest $request, #[RequestBody] string $body = '', array $tags = [], $untyped = null): array
    {
        return [$request, $body, $tags, $untyped];
    }

    #[GetMapping('/filter')]
    public function filter(?UnboundFilter $filter = null, string ...$rest): array
    {
        return [$filter, $rest];
    }
}

#[RequestMapping('/shops/{shopId}')]
final class UnboundShopController extends Controller
{
    #[GetMapping('items/{itemId}')]
    public function item(int $shopId, int $itemId): array
    {
        return [$shopId, $itemId];
    }
}

final class UnboundAnyController extends Controller
{
    #[RequestMapping('/any')]
    public function any(?string $q = null): array
    {
        return [$q];
    }
}
