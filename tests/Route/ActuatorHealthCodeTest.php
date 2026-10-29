<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Http\Health\HealthIndicatorInterface;
use Flytachi\Winter\Kernel\Http\Health\Status;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The health verdict must reach the response code, not only the body.
 *
 * `/actuator/health` used to answer 200 unconditionally while reporting `status: down`
 * inside it, so everything that reads the code instead of the body — a container health
 * check, a k8s liveness/readiness probe, a load balancer — treated a dead application as
 * healthy. A pod with an unreachable database would never leave rotation.
 *
 * `degraded` stays 200 on purpose: it means working worse, not not working. A probe that
 * pulled the instance out over it would escalate a partial outage into a full one.
 */
final class ActuatorHealthCodeTest extends TestCase
{
    /** @param list<string> $expose */
    private function actuator(array $expose = ['*'], ?string $guard = null, bool $guardHealth = false): Router
    {
        $router = new Router();
        new ReflectionMethod(Router::class, 'registerHealth')
            ->invoke($router, StubIndicator::class, $guard, $expose, $guardHealth);

        return $router;
    }

    /** @param list<string> $expose */
    private function get(string $uri, array $expose = ['*'], ?string $guard = null, bool $guardHealth = false): FakeResponse
    {
        $response = new FakeResponse();
        $this->actuator($expose, $guard, $guardHealth)->handle(new FakeRequest('GET', $uri), $response);

        return $response;
    }

    /** The guard middleware is built by the container, as in an application. */
    protected function setUp(): void
    {
        \Flytachi\Winter\DI\Container::init();
    }

    protected function tearDown(): void
    {
        StubIndicator::$report = ['status' => 'up', 'components' => []];
    }

    public function test_down_answers_503(): void
    {
        StubIndicator::$report = ['status' => 'down', 'components' => []];

        $response = $this->get('/actuator/health');

        self::assertSame(503, $response->status);
        self::assertSame('down', $response->json()['status'], 'the body still carries the report');
    }

    public function test_degraded_answers_200(): void
    {
        StubIndicator::$report = ['status' => 'degraded', 'components' => []];

        self::assertSame(200, $this->get('/actuator/health')->status);
    }

    public function test_up_answers_200(): void
    {
        self::assertSame(200, $this->get('/actuator/health')->status);
    }

    /** `/actuator` falls through to the health method, so it must carry the verdict too. */
    public function test_the_bare_actuator_route_answers_503_when_down(): void
    {
        StubIndicator::$report = ['status' => 'down', 'components' => []];

        self::assertSame(503, $this->get('/actuator')->status);
    }

    /**
     * Only health reports a verdict. Another endpoint may use the word `status` for
     * something of its own — a deployment state, a licence, a queue — and that must not
     * be read as "the application is down".
     */
    public function test_a_status_key_on_another_endpoint_is_not_a_verdict(): void
    {
        self::assertSame('down', new StubIndicator()->info()['status'], 'the fixture must bait it');

        self::assertSame(200, $this->get('/actuator/info')->status);
    }

    /** A custom indicator may hand back the enum rather than its value. */
    public function test_a_status_enum_is_understood(): void
    {
        StubIndicator::$report = ['status' => Status::Down, 'components' => []];

        self::assertSame(503, $this->get('/actuator/health')->status);
    }

    /**
     * PHP finds a method in any letter case: `/actuator/HEALTH` ran health(), and a check
     * that compared names answered 200 for an application that was down.
     */
    public function test_health_in_another_case_still_answers_503(): void
    {
        StubIndicator::$report = ['status' => 'down', 'components' => []];

        self::assertSame(503, $this->get('/actuator/HEALTH')->status);
        self::assertSame(503, $this->get('/actuator/Health')->status);
    }

    /** @return array<string, array{string}> */
    public static function notEndpoints(): array
    {
        return [
            'protected helper' => ['helperProtected'],
            'private helper'   => ['helperPrivate'],
            'static method'    => ['helperStatic'],
            'magic method'     => ['__toString'],
            'unknown'          => ['nope'],
        ];
    }

    /**
     * Only a public instance method is an endpoint. A protected or private helper used to
     * answer 500 with "Call to protected method …" and an error in the log.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notEndpoints')]
    public function test_what_is_not_an_endpoint_answers_404(string $name): void
    {
        self::assertSame(404, $this->get('/actuator/' . $name)->status);
    }

    // ── What answers ───────────────────────────────────────────────────────────

    /** Only health answers unless more are named: the others draw a map of the application. */
    public function test_by_default_only_health_answers(): void
    {
        self::assertSame(200, $this->get('/actuator/health', ['health'])->status);
        self::assertSame(200, $this->get('/actuator', ['health'])->status);
        foreach (['info', 'metrics', 'mappings', 'loggers', 'pools', 'env'] as $endpoint) {
            self::assertSame(404, $this->get('/actuator/' . $endpoint, ['health'])->status, $endpoint);
        }
    }

    public function test_the_named_endpoints_answer(): void
    {
        self::assertSame(200, $this->get('/actuator/info', ['health', 'info'])->status);
        self::assertSame(404, $this->get('/actuator/mappings', ['health', 'info'])->status);
    }

    /** A typo in the list would answer 404 for good, and nobody would know why. */
    public function test_an_exposed_name_without_an_endpoint_stops_the_boot(): void
    {
        $this->expectException(\Flytachi\Winter\Kernel\App\ApplicationConfigException::class);
        $this->expectExceptionMessage("names 'metrcs'");
        $this->actuator(['health', 'metrcs']);
    }

    public function test_a_helper_cannot_be_exposed(): void
    {
        $this->expectException(\Flytachi\Winter\Kernel\App\ApplicationConfigException::class);
        $this->actuator(['helperProtected']);
    }

    /** Probes do not pass a guard: it leaves health open, and closes the rest. */
    public function test_the_guard_leaves_health_open(): void
    {
        self::assertSame(200, $this->get('/actuator/health', ['*'], DenyAll::class)->status);
        self::assertSame(200, $this->get('/actuator', ['*'], DenyAll::class)->status);
        self::assertSame(403, $this->get('/actuator/info', ['*'], DenyAll::class)->status);
    }

    public function test_guard_health_puts_health_behind_the_guard_too(): void
    {
        self::assertSame(403, $this->get('/actuator/health', ['*'], DenyAll::class, true)->status);
        self::assertSame(403, $this->get('/actuator', ['*'], DenyAll::class, true)->status);
    }

    public function test_a_report_without_a_status_stays_200(): void
    {
        StubIndicator::$report = ['components' => []];

        self::assertSame(200, $this->get('/actuator/health')->status);
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/** A guard that lets nobody through. */
final class DenyAll extends \Flytachi\Winter\Kernel\Http\Stereotype\Middleware
{
    public function before(\Flytachi\Winter\Kernel\Http\Contracts\HttpRequest $request, \Flytachi\Winter\Kernel\Http\Contracts\HttpResponse $response): void
    {
        throw new \Flytachi\Winter\Kernel\Http\Response\ResponseException('denied', \Flytachi\Winter\Base\HttpCode::FORBIDDEN);
    }
}

/** An indicator whose health report the test dictates. */
final class StubIndicator implements HealthIndicatorInterface
{
    public static array $report = ['status' => 'up', 'components' => []];

    public function health(): array
    {
        return self::$report;
    }

    public function pools(): array
    {
        return ['status' => 'up', 'pools' => []];
    }

    /** Carries a `status` of its own — the bait for the health-only check. */
    public function info(): array
    {
        return ['framework' => 'winter', 'status' => 'down'];
    }

    public function metrics(): array
    {
        return [];
    }

    public function env(): array
    {
        return [];
    }

    public function loggers(): array
    {
        return [];
    }

    public function mappings(): array
    {
        return [];
    }

    public static function helperStatic(): array
    {
        return [];
    }

    public function __toString(): string
    {
        return 'stub';
    }

    protected function helperProtected(): array
    {
        return [];
    }

    private function helperPrivate(): array
    {
        return [];
    }
}
