<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Configuration;

use Flytachi\Winter\Kernel\Http\Health\Health;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicatorInterface;
use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        self::resetState();
    }

    protected function tearDown(): void
    {
        self::resetState();
    }

    private static function resetState(): void
    {
        $ref = new ReflectionClass(Health::class);
        $ref->getProperty('config')->setValue(null, null);
        $ref->getProperty('rootDir')->setValue(null, '');
        $ref->getProperty('mappings')->setValue(null, []);
    }

    // ── configure() / getConfig() ────────────────────────────────────────────

    public function test_get_config_returns_null_before_configure(): void
    {
        self::assertNull(Health::getConfig());
    }

    public function test_configure_with_defaults_uses_built_in_indicator_and_no_middleware(): void
    {
        Health::configure();

        self::assertSame(
            ['indicator' => HealthIndicator::class, 'middleware' => null],
            Health::getConfig()
        );
    }

    public function test_configure_accepts_custom_indicator(): void
    {
        $indicator = new class implements HealthIndicatorInterface {
            public function health(): array { return []; }
            public function pools(): array { return []; }
            public function info(): array { return []; }
            public function metrics(): array { return []; }
            public function env(): array { return []; }
            public function loggers(): array { return []; }
            public function mappings(): array { return []; }
        };

        Health::configure(indicator: $indicator::class);
        self::assertSame($indicator::class, Health::getConfig()['indicator']);
    }

    public function test_configure_accepts_custom_middleware(): void
    {
        $mw = new class extends Middleware {};
        Health::configure(middleware: $mw::class);

        self::assertSame($mw::class, Health::getConfig()['middleware']);
    }

    public function test_configure_overwrites_previous_call(): void
    {
        Health::configure();
        self::assertNull(Health::getConfig()['middleware']);

        $mw = new class extends Middleware {};
        Health::configure(middleware: $mw::class);
        self::assertSame($mw::class, Health::getConfig()['middleware']);
    }

    // ── rootDir setter / getter ──────────────────────────────────────────────

    public function test_root_dir_defaults_to_empty_string(): void
    {
        self::assertSame('', Health::getRootDir());
    }

    public function test_set_root_dir_round_trips(): void
    {
        Health::setRootDir('/var/app');
        self::assertSame('/var/app', Health::getRootDir());
    }

    // ── mappings setter / getter ─────────────────────────────────────────────

    public function test_mappings_default_to_empty_array(): void
    {
        self::assertSame([], Health::getMappings());
    }

    public function test_set_mappings_round_trips_arbitrary_payload(): void
    {
        $payload = [['method' => 'GET', 'uri' => '/x'], ['method' => 'POST', 'uri' => '/y']];
        Health::setMappings($payload);
        self::assertSame($payload, Health::getMappings());
    }

    // ── system info helpers ──────────────────────────────────────────────────

    public function test_cpu_returns_load_average_and_core_count_keys(): void
    {
        $cpu = Health::cpu();
        self::assertArrayHasKey('load_average', $cpu);
        self::assertArrayHasKey('core_count', $cpu);
        self::assertGreaterThanOrEqual(1, $cpu['core_count']);
        self::assertIsArray($cpu['load_average']);
    }

    public function test_memory_returns_usage_peak_limit_keys(): void
    {
        $mem = Health::memory();
        self::assertArrayHasKey('usage', $mem);
        self::assertArrayHasKey('peak', $mem);
        self::assertArrayHasKey('limit', $mem);
        self::assertGreaterThan(0, $mem['usage']);
        self::assertGreaterThanOrEqual($mem['usage'], $mem['peak']);
    }

    public function test_memory_limit_is_minus_one_when_unlimited_or_positive_int_otherwise(): void
    {
        $limit = Health::memory()['limit'];
        self::assertIsInt($limit);
        self::assertTrue($limit === -1 || $limit > 0, 'limit must be -1 or > 0');
    }

    public function test_disk_returns_free_total_and_usage_percent(): void
    {
        $disk = Health::disk();
        self::assertArrayHasKey('free', $disk);
        self::assertArrayHasKey('total', $disk);
        self::assertArrayHasKey('usage_percent', $disk);
        self::assertGreaterThanOrEqual(0, $disk['usage_percent']);
        self::assertLessThanOrEqual(100, $disk['usage_percent']);
    }

    public function test_system_returns_os_release_and_hostname(): void
    {
        $sys = Health::system();
        self::assertArrayHasKey('os', $sys);
        self::assertArrayHasKey('release', $sys);
        self::assertArrayHasKey('hostname', $sys);
        self::assertNotSame('', $sys['os']);
        self::assertNotSame('', $sys['hostname']);
    }

    public function test_uptime_seconds_returns_int_or_null(): void
    {
        $uptime = Health::uptimeSeconds();
        // /proc/uptime exists on Linux, missing on macOS / containers without procfs.
        self::assertTrue($uptime === null || is_int($uptime));
        if (is_int($uptime)) {
            self::assertGreaterThan(0, $uptime);
        }
    }
}
