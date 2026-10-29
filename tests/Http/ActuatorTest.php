<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\App\Attribute\EnableActuator;
use Flytachi\Winter\Kernel\Http\Health\Health;
use Flytachi\Winter\Kernel\Http\Health\HealthContributor;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;
use Flytachi\Winter\Kernel\Http\Health\HealthStatus;
use Flytachi\Winter\Kernel\Http\Health\Status;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The actuator: the HealthStatus value object, HealthContributor discovery/merge in
 * the aggregator, and the #[EnableActuator] → Health wiring. Health is a static
 * registry, so each test resets it first.
 */
final class ActuatorTest extends TestCase
{
    protected function setUp(): void
    {
        new ReflectionProperty(Health::class, 'config')->setValue(null, null);
        new ReflectionProperty(Health::class, 'contributors')->setValue(null, []);
        Health::setRootDir('');
        Container::init();
    }

    // ── HealthStatus value object ─────────────────────────────────────────────

    public function test_status_factories(): void
    {
        self::assertSame(Status::Up, HealthStatus::up()->status());
        self::assertSame(Status::Degraded, HealthStatus::degraded()->status());
        self::assertSame(Status::Down, HealthStatus::down()->status());
    }

    public function test_to_array_carries_status_and_details(): void
    {
        self::assertSame(
            ['status' => 'up', 'details' => ['latency_ms' => 3]],
            HealthStatus::up()->withDetail('latency_ms', 3)->toArray(),
        );
        self::assertSame(
            ['status' => 'down', 'details' => []],
            HealthStatus::down()->toArray(),
        );
    }

    // ── Contributor discovery / aggregation ───────────────────────────────────

    public function test_contributor_is_merged_under_its_name(): void
    {
        Health::setContributors([UpContributor::class]);

        $components = (new HealthIndicator())->health()['components'];

        self::assertSame(['status' => 'up', 'details' => ['x' => 1]], $components['up-check']);
    }

    public function test_down_contributor_forces_overall_down(): void
    {
        Health::setContributors([DownContributor::class]);

        $report = (new HealthIndicator())->health();

        self::assertSame('down', $report['components']['down-check']['status']);
        self::assertSame('down', $report['status']);
    }

    /**
     * A check that throws is that component down — not a 500 for the whole report, which
     * hid which component failed and every other component's state with it.
     */
    public function test_a_throwing_contributor_is_down_and_the_rest_still_report(): void
    {
        Health::setContributors([ThrowingContributor::class, UpContributor::class]);

        $report = $this->healthWithDebug(false);

        self::assertSame('down', $report['status']);
        self::assertSame('up', $report['components']['up-check']['status']);
        self::assertArrayHasKey('disk', $report['components']);
    }

    /**
     * The endpoint is open unless guarded, and an exception's message may carry a host, a
     * user or a path: outside DEBUG the report says only that the check failed.
     */
    public function test_a_failed_check_hides_the_exception_outside_debug(): void
    {
        Health::setContributors([ThrowingContributor::class]);

        self::assertSame(
            ['status' => 'down', 'details' => ['error' => 'check failed']],
            $this->healthWithDebug(false)['components']['broker'],
        );
    }

    public function test_a_failed_check_shows_the_exception_in_debug(): void
    {
        Health::setContributors([ThrowingContributor::class]);

        self::assertSame(
            ['status' => 'down', 'details' => ['error' => 'broker unreachable', 'exception' => \RuntimeException::class]],
            $this->healthWithDebug(true)['components']['broker'],
        );
    }

    /** @return array<string, mixed> */
    private function healthWithDebug(bool $debug): array
    {
        $had = array_key_exists('DEBUG', $_ENV);
        $previous = $_ENV['DEBUG'] ?? null;
        $_ENV['DEBUG'] = $debug ? 'true' : 'false';
        try {
            return (new HealthIndicator())->health();
        } finally {
            if ($had) {
                $_ENV['DEBUG'] = $previous;
            } else {
                unset($_ENV['DEBUG']);
            }
        }
    }

    /** One that cannot even be built is named after its class. */
    public function test_a_contributor_that_cannot_be_built_is_down_under_its_class_name(): void
    {
        Health::setContributors([UnbuildableContributor::class]);

        $report = (new HealthIndicator())->health();

        self::assertSame('down', $report['components']['UnbuildableContributor']['status']);
        self::assertSame('down', $report['status']);
    }

    public function test_contributor_may_override_a_builtin_component(): void
    {
        Health::setContributors([DbOverrideContributor::class]);

        $components = (new HealthIndicator())->health()['components'];

        self::assertSame(['status' => 'up', 'details' => ['driver' => 'fake']], $components['db']);
    }

    public function test_no_contributors_keeps_builtin_components_only(): void
    {
        $components = (new HealthIndicator())->health()['components'];

        self::assertArrayHasKey('disk', $components);
        self::assertArrayNotHasKey('up-check', $components);
    }

    // ── #[EnableActuator] wiring ──────────────────────────────────────────────

    public function test_no_attribute_leaves_actuator_off(): void
    {
        new ReflectionMethod(PlainActuatorApp::class, 'applyActuator')->invoke(null, []);

        self::assertNull(Health::getConfig());
    }

    public function test_attribute_enables_actuator_with_guard_and_contributors(): void
    {
        new ReflectionMethod(GuardedActuatorApp::class, 'applyActuator')
            ->invoke(null, [new ReflectionClass(UpContributor::class)]);

        self::assertSame(
            ['indicator' => HealthIndicator::class, 'middleware' => 'Acme\\Guard', 'expose' => ['health'], 'guardHealth' => false],
            Health::getConfig(),
        );
        self::assertSame([UpContributor::class], Health::getContributors());
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

final class UpContributor implements HealthContributor
{
    public function name(): string
    {
        return 'up-check';
    }

    public function check(): HealthStatus
    {
        return HealthStatus::up()->withDetail('x', 1);
    }
}

final class DownContributor implements HealthContributor
{
    public function name(): string
    {
        return 'down-check';
    }

    public function check(): HealthStatus
    {
        return HealthStatus::down();
    }
}

final class ThrowingContributor implements HealthContributor
{
    public function name(): string
    {
        return 'broker';
    }

    public function check(): HealthStatus
    {
        throw new \RuntimeException('broker unreachable');
    }
}

final class UnbuildableContributor implements HealthContributor
{
    public function __construct(private readonly NotABinding $missing)
    {
    }

    public function name(): string
    {
        return 'unbuildable';
    }

    public function check(): HealthStatus
    {
        return HealthStatus::up();
    }
}

/** An interface nothing implements: the container cannot build what needs it. */
interface NotABinding
{
}

final class DbOverrideContributor implements HealthContributor
{
    public function name(): string
    {
        return 'db';
    }

    public function check(): HealthStatus
    {
        return HealthStatus::up()->withDetail('driver', 'fake');
    }
}

final class PlainActuatorApp extends WinterApplication
{
    public static function main(array $a): never
    {
        exit(0);
    }
}

#[EnableActuator(middleware: 'Acme\\Guard')]
final class GuardedActuatorApp extends WinterApplication
{
    public static function main(array $a): never
    {
        exit(0);
    }
}
