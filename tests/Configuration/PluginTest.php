<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Configuration;

use Flytachi\Winter\Kernel\Exception\Error;
use Flytachi\Winter\Kernel\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * The registry of imported packages.
 *
 * The interesting part is what a package's "code" is taken to be. It used to be guessed
 * — `src/` if present, the whole install directory otherwise — and both branches were
 * wrong for a package laid out any other way: one caller skipped it entirely, the other
 * handed `require_once` the package's `resources/` templates and its `bootstrap.php`.
 * The answer is in the package's own composer.json, which is what these tests pin.
 */
final class PluginTest extends TestCase
{
    /** A real dependency of the kernel, so it is always installed. */
    private const string REAL_PACKAGE = 'flytachi/winter-base';
    private const string FAKE_PACKAGE = 'acme/non-existent-test-pkg';

    protected function setUp(): void
    {
        Plugin::forget();
    }

    protected function tearDown(): void
    {
        Plugin::forget();
    }

    // ── Default state ─────────────────────────────────────────────────────────

    public function test_nothing_is_registered_before_an_import(): void
    {
        self::assertSame([], Plugin::all());
        self::assertSame([], Plugin::roots());
        self::assertSame([], Plugin::routed());
    }

    // ── Registration ──────────────────────────────────────────────────────────

    public function test_a_package_is_recorded_with_its_name_and_path(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/base');

        $plugin = Plugin::all()[0];

        self::assertSame(self::REAL_PACKAGE, $plugin->package);
        self::assertSame('/base', $plugin->prefix);
        self::assertDirectoryExists($plugin->path);
        self::assertSame(rtrim($plugin->path, '/\\'), $plugin->path, 'no trailing separator');
    }

    /**
     * @param string $given A prefix written the way a developer might write it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sloppyPrefixes')]
    public function test_a_prefix_is_normalised(string $given): void
    {
        Plugin::registry(self::REAL_PACKAGE, $given);

        self::assertSame('/billing', Plugin::all()[0]->prefix);
    }

    /** @return iterable<string, array{string}> */
    public static function sloppyPrefixes(): iterable
    {
        yield 'no leading slash'  => ['billing'];
        yield 'trailing slash'    => ['/billing/'];
        yield 'both'              => ['billing/'];
    }

    public function test_import_order_is_preserved(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/base');
        Plugin::registry('flytachi/winter-di', '/di');

        self::assertSame(
            [self::REAL_PACKAGE, 'flytachi/winter-di'],
            array_map(static fn($p): string => $p->package, Plugin::all()),
            'the scan applies packages in this order, so it has to be the declared one',
        );
    }

    /**
     * `''` and `'/'` both normalise to `'/'`, and `MappingCollector` then builds
     * `'/' . '/' . 'users'` — `//users`, a path no request matches. It used to mount
     * silently. Since null already means "import without routes", nothing of value is
     * being refused here.
     *
     * @param string $prefix A prefix that cannot be addressed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unaddressablePrefixes')]
    public function test_a_prefix_that_resolves_to_root_is_refused(string $prefix): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('is not a mount point');

        Plugin::registry(self::REAL_PACKAGE, $prefix);
    }

    /** @return iterable<string, array{string}> */
    public static function unaddressablePrefixes(): iterable
    {
        yield 'empty'        => [''];
        yield 'single slash' => ['/'];
        yield 'only slashes' => ['///'];
        yield 'whitespace'   => [' '];
    }

    public function test_the_refusal_points_at_the_two_ways_out(): void
    {
        try {
            Plugin::registry(self::REAL_PACKAGE, '/');
            self::fail('should have been refused');
        } catch (Error $e) {
            self::assertStringContainsString('/billing', $e->getMessage(), 'a real prefix');
            self::assertStringContainsString('without mounting', $e->getMessage(), 'or none at all');
        }
    }

    public function test_two_packages_cannot_claim_one_prefix(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/billing');

        $this->expectException(Error::class);
        Plugin::registry('flytachi/winter-di', '/billing');
    }

    // ── Routes are optional ───────────────────────────────────────────────────

    /** A package of services or commands has no URL to invent. */
    public function test_a_package_may_be_imported_without_a_prefix(): void
    {
        Plugin::registry(self::REAL_PACKAGE);

        $plugin = Plugin::all()[0];

        self::assertNull($plugin->prefix);
        self::assertFalse($plugin->mountsRoutes());
        self::assertSame([], Plugin::routed(), 'nothing to mount');
        self::assertNotSame([], $plugin->roots, 'but its code is still scanned');
    }

    public function test_only_prefixed_packages_are_routed(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/base');
        Plugin::registry('flytachi/winter-di');

        self::assertSame(
            [self::REAL_PACKAGE],
            array_map(static fn($p): string => $p->package, Plugin::routed()),
        );
    }

    /** Two packages with no prefix do not collide — there is no prefix to collide over. */
    public function test_several_packages_without_a_prefix_coexist(): void
    {
        Plugin::registry(self::REAL_PACKAGE);
        Plugin::registry('flytachi/winter-di');

        self::assertCount(2, Plugin::all());
    }

    // ── Scan roots ────────────────────────────────────────────────────────────

    public function test_roots_come_from_the_packages_own_autoload(): void
    {
        Plugin::registry(self::REAL_PACKAGE);

        $plugin = Plugin::all()[0];
        $manifest = json_decode((string) file_get_contents($plugin->path . '/composer.json'), true);
        $declared = array_values($manifest['autoload']['psr-4']);

        self::assertCount(count($declared), $plugin->roots);
        foreach ($plugin->roots as $root) {
            self::assertDirectoryExists($root);
            self::assertStringStartsWith($plugin->path, $root);
        }
    }

    /** The whole point: the install directory itself is never a scan root. */
    public function test_the_package_root_is_not_scanned(): void
    {
        Plugin::registry(self::REAL_PACKAGE);

        $plugin = Plugin::all()[0];

        self::assertNotContains(
            $plugin->path,
            $plugin->roots,
            'scanning the install directory would require_once its templates and bootstrap',
        );
    }

    public function test_roots_of_every_package_are_gathered_in_import_order(): void
    {
        Plugin::registry(self::REAL_PACKAGE);
        Plugin::registry('flytachi/winter-di');

        $roots = Plugin::roots();

        self::assertSame(
            array_merge(Plugin::all()[0]->roots, Plugin::all()[1]->roots),
            $roots,
        );
    }

    /**
     * The layout that used to fall between the two guesses: a package keeping its code
     * in `main/` was skipped by the router (no `src/`) and scanned from its root by the
     * boot scan — which meant handing `require_once` its `bootstrap.php`, whose
     * `Application` class collides with the host's.
     */
    public function test_a_package_that_keeps_its_code_outside_src_is_resolved(): void
    {
        $dir = sys_get_temp_dir() . '/wk_pkg_' . bin2hex(random_bytes(4));
        mkdir($dir . '/main', 0777, true);
        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'test/dep',
            'autoload' => ['psr-4' => ['Dep\\Main\\' => 'main/']],
        ]));
        // The two files that must never be scanned.
        file_put_contents($dir . '/bootstrap.php', '<?php final class Application {}');
        mkdir($dir . '/resources');

        try {
            $roots = new \ReflectionMethod(Plugin::class, 'rootsOf')->invoke(null, 'test/dep', $dir);

            self::assertSame([$dir . '/main'], $roots);
        } finally {
            @unlink($dir . '/bootstrap.php');
            @unlink($dir . '/composer.json');
            @rmdir($dir . '/resources');
            @rmdir($dir . '/main');
            @rmdir($dir);
        }
    }

    public function test_a_package_declaring_no_psr4_is_refused_with_a_reason(): void
    {
        $dir = sys_get_temp_dir() . '/wk_pkg_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'test/plain']));

        try {
            $this->expectException(Error::class);
            $this->expectExceptionMessage('declares no autoload.psr-4');

            new \ReflectionMethod(Plugin::class, 'rootsOf')->invoke(null, 'test/plain', $dir);
        } finally {
            @unlink($dir . '/composer.json');
            @rmdir($dir);
        }
    }

    // ── Missing packages ──────────────────────────────────────────────────────

    public function test_a_missing_required_package_is_an_error(): void
    {
        $this->expectException(Error::class);

        Plugin::registry(self::FAKE_PACKAGE, '/nope');
    }

    public function test_a_missing_optional_package_is_skipped(): void
    {
        Plugin::registry(self::FAKE_PACKAGE, '/nope', required: false);

        self::assertSame([], Plugin::all());
    }

    public function test_forget_empties_the_registry(): void
    {
        Plugin::registry(self::REAL_PACKAGE, '/base');
        Plugin::forget();

        self::assertSame([], Plugin::all());
    }
}
