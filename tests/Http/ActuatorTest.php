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
            ['indicator' => HealthIndicator::class, 'middleware' => 'Acme\\Guard'],
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
