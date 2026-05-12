<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Configuration;

use Flytachi\Winter\K2\Exception\Error;
use Flytachi\Winter\K2\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginTest extends TestCase
{
    /**
     * Use a real installed Composer package as the "happy path" candidate —
     * winter-base is a hard dependency of winter-kernel so it is always present.
     */
    private const REAL_PACKAGE = 'flytachi/winter-base';
    private const FAKE_PACKAGE = 'acme/non-existent-test-pkg';

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
        (new ReflectionClass(Plugin::class))->getProperty('plugins')->setValue(null, []);
    }

    // ── default state ────────────────────────────────────────────────────────

    public function test_get_plugins_is_empty_before_any_registration(): void
    {
        self::assertSame([], Plugin::getPlugins());
    }

    // ── happy path ───────────────────────────────────────────────────────────

    public function test_registry_records_real_package_under_normalised_prefix(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/billing');

        $registered = Plugin::getPlugins();
        self::assertArrayHasKey('/billing', $registered);
        self::assertNotSame('', $registered['/billing']);
        self::assertDirectoryExists($registered['/billing']);
    }

    public function test_registry_normalises_prefix_with_no_leading_slash(): void
    {
        Plugin::registry(self::REAL_PACKAGE, 'billing');
        self::assertArrayHasKey('/billing', Plugin::getPlugins());
    }

    public function test_registry_normalises_prefix_with_trailing_slash(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/billing/');
        self::assertArrayHasKey('/billing', Plugin::getPlugins());
    }

    public function test_registry_normalises_prefix_with_both_slashes(): void
    {
        Plugin::registry(self::REAL_PACKAGE, 'billing/');
        self::assertArrayHasKey('/billing', Plugin::getPlugins());
    }

    public function test_registry_records_install_path_without_trailing_slash(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/billing');
        $path = Plugin::getPlugins()['/billing'];
        self::assertSame(rtrim($path, '/\\'), $path);
    }

    public function test_registry_supports_multiple_distinct_prefixes(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/a');
        Plugin::registry(self::REAL_PACKAGE, '/b');

        $registered = Plugin::getPlugins();
        self::assertArrayHasKey('/a', $registered);
        self::assertArrayHasKey('/b', $registered);
        self::assertCount(2, $registered);
    }

    // ── failure modes ────────────────────────────────────────────────────────

    public function test_registry_throws_when_required_package_is_missing(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage("Plugin '" . self::FAKE_PACKAGE . "' has no install path");
        Plugin::registry(self::FAKE_PACKAGE, '/x');
    }

    public function test_registry_silently_skips_missing_optional_package(): void
    {
        Plugin::registry(self::FAKE_PACKAGE, '/x', required: false);
        self::assertSame([], Plugin::getPlugins());
    }

    public function test_registry_throws_when_prefix_already_registered(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/billing');

        $this->expectException(Error::class);
        $this->expectExceptionMessage("Plugin prefix '/billing' already registered");
        Plugin::registry(self::REAL_PACKAGE, '/billing');
    }

    public function test_registry_throws_on_duplicate_prefix_even_when_normalised(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/billing');

        // 'billing/' normalises to '/billing' → still a duplicate
        $this->expectException(Error::class);
        $this->expectExceptionMessage("Plugin prefix '/billing' already registered");
        Plugin::registry(self::REAL_PACKAGE, 'billing/');
    }
}
