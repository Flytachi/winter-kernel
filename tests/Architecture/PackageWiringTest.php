<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wiring that packages cannot do for themselves.
 *
 * PPA is a package now, and a package must not reach for the framework's globals: it
 * takes a logger, a timezone provider and a telemetry store from whoever boots it. That
 * makes it usable — and testable — outside the kernel, and it moves the responsibility
 * for installing all three into `Kernel::init()`.
 *
 * Nothing else would notice if that wiring were deleted. The tests of each behaviour
 * install it themselves, exactly as the kernel does, so they would keep passing while a
 * real application lost its pool logs, sent every database session the wrong timezone,
 * and reported no pool statistics at all. This test is the reason that cannot happen
 * quietly.
 *
 * It reads the source rather than booting the kernel on purpose: `Kernel::init()` loads
 * the environment, the logger and the thread launcher, and a test that had to survive
 * all of that would be answering a different question.
 */
final class PackageWiringTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wiring(): iterable
    {
        yield 'the pool logs through the framework logger' => [
            'PpaConnectionPool::setLogger(',
            'without it the pool is silent: PPA defaults to a NullLogger',
        ];
        yield 'database sessions follow the request timezone' => [
            'PpaConnectionPool::setTimezoneProvider(',
            'without it no SET TIMEZONE is sent and a pooled connection keeps the previous request zone',
        ];
        yield 'pool statistics reach /actuator/health' => [
            'PoolTelemetry::setStoreProvider(',
            'without it telemetry has nowhere to publish and health reports no pools',
        ];
    }

    #[DataProvider('wiring')]
    public function test_kernel_init_installs_it(string $call, string $consequence): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Kernel.php');

        self::assertStringContainsString($call, $source, "Kernel::init() must call {$call}) — {$consequence}");
    }

    /**
     * The entry points that cannot work without the package must say so, not crash.
     *
     * `db` reads configs and builds declarations; the generators write files that import
     * the package. Both are useless without it, and both would otherwise die with
     * `Class "Flytachi\Winter\Ppa\..." not found` — which reads as a broken framework
     * rather than a package the application chose not to install.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function entryPoints(): iterable
    {
        yield "the db command" => ['console/Command/Db.php', "DepSupport::demand(Dep::Ppa"];
        yield "the code generators" => ['console/Command/Make.php', "requireDep(Dep::Ppa"];
        yield "the Redis generators" => ['console/Command/Make.php', "requireDep(Dep::Redis"];
    }

    #[DataProvider('entryPoints')]
    public function test_it_refuses_with_an_instruction(string $file, string $call): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);

        self::assertStringContainsString(
            $call,
            $source,
            "{$file} must ask DepSupport before touching the package",
        );
    }

    /**
     * The kernel must boot, serve and shut down without the package.
     *
     * Each of these runs on a path every application takes — boot, worker start, worker
     * exit, health probe — so an unguarded call would not fail some applications, it
     * would fail every application that did not install a database layer.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function runtimePaths(): iterable
    {
        yield 'boot' => ['src/Kernel.php', 'DepSupport::has(Dep::Ppa)'];
        yield 'worker start and exit' => ['src/WinterApplication.php', 'DepSupport::has(Dep::Ppa)'];
        yield 'health probe' => ['src/Http/Health/HealthIndicator.php', 'DepSupport::has(Dep::Ppa)'];
        yield 'health probe, Redis' => ['src/Http/Health/HealthIndicator.php', 'DepSupport::has(Dep::Redis)'];
    }

    #[DataProvider('runtimePaths')]
    public function test_the_runtime_path_is_guarded(string $file, string $call): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);

        self::assertStringContainsString($call, $source, "{$file} must not assume the package is installed");
    }

    /**
     * The store is built lazily, and that is not a detail: constructing a FileStorage
     * creates its directory, so an eager call would leave an empty `runnable/ppa.pool/`
     * in every application, including those that never open a database connection.
     */
    public function test_the_telemetry_store_is_installed_lazily(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Kernel.php');

        self::assertMatchesRegularExpression(
            '/setStoreProvider\(\s*static fn\(\)/',
            $source,
            'the store must be handed over as a provider, not as an already-built storage',
        );
    }
}
