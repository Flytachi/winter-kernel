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
    private function actuator(): Router
    {
        $router = new Router();
        new ReflectionMethod(Router::class, 'registerHealth')
            ->invoke($router, StubIndicator::class, null);

        return $router;
    }

    private function get(string $uri): FakeResponse
    {
        $response = new FakeResponse();
        $this->actuator()->handle(new FakeRequest('GET', $uri), $response);

        return $response;
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

    public function test_a_report_without_a_status_stays_200(): void
    {
        StubIndicator::$report = ['components' => []];

        self::assertSame(200, $this->get('/actuator/health')->status);
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/** An indicator whose health report the test dictates. */
final class StubIndicator implements HealthIndicatorInterface
{
    public static array $report = ['status' => 'up', 'components' => []];

    public function health(): array
    {
        return self::$report;
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
}
