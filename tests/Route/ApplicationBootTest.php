<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\Kernel\Core\KernelConfig;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The assembly, not the parts: a real controller is discovered by scanning, built by
 * the container, and reached through the router.
 *
 * Every other router test registers its routes by hand, which skips the chain that
 * actually turns a project into a running application — attribute scan → DI → route
 * table → parameter resolution. That chain is exactly where a failure hides while all
 * unit tests stay green (as the dispatch runner regression showed), so it gets its own
 * fixture app under `Fixtures/App`.
 */
final class ApplicationBootTest extends TestCase
{
    private static ?Router $router = null;
    private static ?string $originalRoot = null;

    public static function setUpBeforeClass(): void
    {
        $prop = new ReflectionProperty(KernelConfig::class, 'pathRoot');
        self::$originalRoot = $prop->isInitialized() ? $prop->getValue() : null;

        // Errors are silenced when DEBUG is off, which would hide a boot failure.
        $_ENV['DEBUG'] = 'true';

        $appDir = __DIR__ . '/Fixtures/App';
        Kernel::init(pathRoot: $appDir);

        $container = Container::init();
        Scanner::run($appDir)->collect(new DICollector($container))->execute();

        self::$router = Router::fromScan($appDir);
    }

    public static function tearDownAfterClass(): void
    {
        unset($_ENV['DEBUG']);
        self::$router = null;

        if (self::$originalRoot !== null) {
            KernelConfig::$pathRoot = self::$originalRoot;
        }
    }

    /** @param array<string, string> $query */
    private function send(string $method, string $uri, array $query = []): FakeResponse
    {
        $response = new FakeResponse();
        self::$router->handle(new FakeRequest($method, $uri, [], $query), $response);

        return $response;
    }

    public function test_the_scan_discovers_routes_from_attributes(): void
    {
        $routes = array_map(
            static fn(array $r): string => $r['method'] . ' ' . $r['path'],
            self::$router->getRoutesSummary(),
        );

        self::assertContains('GET /demo/ping', $routes, 'nothing was registered by hand here');
        self::assertContains('POST /demo/items', $routes);
    }

    public function test_the_class_prefix_combines_with_the_method_path(): void
    {
        // #[RequestMapping('/demo')] + #[GetMapping('/ping')]
        self::assertSame(200, $this->send('GET', '/demo/ping')->status);
        self::assertSame(404, $this->send('GET', '/ping')->status, 'the prefix is not optional');
    }

    public function test_the_container_injects_the_controller_dependency(): void
    {
        $response = $this->send('GET', '/demo/hello/world');

        // 'hello world' can only come from the autowired GreetingService.
        self::assertSame(['message' => 'hello world'], $response->json());
    }

    public function test_a_path_variable_reaches_the_method_argument(): void
    {
        self::assertSame(['message' => 'hello winter'], $this->send('GET', '/demo/hello/winter')->json());
    }

    public function test_query_parameters_bind_and_defaults_apply(): void
    {
        self::assertSame(
            ['q' => 'winter', 'limit' => 10],
            $this->send('GET', '/demo/search', ['q' => 'winter'])->json(),
            'limit is absent, so its PHP default is used',
        );

        self::assertSame(
            ['q' => 'winter', 'limit' => 5],
            $this->send('GET', '/demo/search', ['q' => 'winter', 'limit' => '5'])->json(),
            'and it is cast to the declared int',
        );
    }

    public function test_the_request_object_is_injectable_by_type(): void
    {
        self::assertSame(
            ['method' => 'GET', 'uri' => '/demo/echo'],
            $this->send('GET', '/demo/echo')->json(),
        );
    }

    public function test_the_http_method_of_the_mapping_is_honoured(): void
    {
        self::assertSame(200, $this->send('POST', '/demo/items')->status);

        $wrongMethod = $this->send('GET', '/demo/items');
        self::assertSame(405, $wrongMethod->status);
        self::assertStringContainsString('POST', (string) $wrongMethod->header_('Allow'));
    }

    public function test_an_unmapped_path_is_not_found(): void
    {
        self::assertSame(404, $this->send('GET', '/demo/nothing-here')->status);
    }

    // ── Class-list cache ───────────────────────────────────────────────────────

    public function test_a_cached_scan_finds_the_same_routes(): void
    {
        $appDir = __DIR__ . '/Fixtures/App';
        $cache  = sys_get_temp_dir() . '/winter-boot-scan-' . getmypid() . '.php';
        @unlink($cache);

        try {
            $walked = Router::fromScan($appDir)->getRoutesSummary();
            Router::fromScan($appDir, cache: $cache);              // builds the cache
            $cached = Router::fromScan($appDir, cache: $cache)->getRoutesSummary();

            self::assertNotSame([], $walked, 'the fixture app must expose routes');
            self::assertSame(
                $walked,
                $cached,
                'reading the class list from cache must not change the route table',
            );
        } finally {
            @unlink($cache);
        }
    }

    public function test_the_cache_is_skipped_when_directories_are_excluded(): void
    {
        $appDir = __DIR__ . '/Fixtures/App';
        $cache  = sys_get_temp_dir() . '/winter-boot-excl-' . getmypid() . '.php';
        @unlink($cache);

        try {
            Router::fromScan($appDir, ['nowhere'], $cache);

            self::assertFileDoesNotExist(
                $cache,
                'exclusions must force a real walk — a cached list was built without them',
            );
        } finally {
            @unlink($cache);
        }
    }
}
