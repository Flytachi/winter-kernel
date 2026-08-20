<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Configuration;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\PluginPackage;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Plugin;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Who is scanned, and in which order.
 *
 * The order is a guarantee, not an accident: contributions that add up do not care, but
 * anything where a later contributor overwrites an earlier one does, and the application
 * has to be the one that wins. It owns the process; a package must not overrule it by
 * virtue of having been walked first — which, before this, is exactly what decided the
 * outcome, since the winner came down to the order the filesystem was traversed.
 */
final class PackageScanOrderTest extends TestCase
{
    private const string REAL_PACKAGE = 'flytachi/winter-base';

    private string $appRoot = '';
    private ?string $originalRoot = null;

    protected function setUp(): void
    {
        Plugin::forget();

        $this->appRoot = sys_get_temp_dir() . '/wk_scan_' . bin2hex(random_bytes(4));
        @mkdir($this->appRoot, 0777, true);
        file_put_contents(
            $this->appRoot . '/ProjectOnly.php',
            "<?php namespace WkScanFixture; final class ProjectOnly {}",
        );

        $prop = new ReflectionProperty(Kernel::class, 'pathRoot');
        $this->originalRoot = $prop->isInitialized() ? $prop->getValue() : null;
        Kernel::$pathRoot = $this->appRoot;
    }

    protected function tearDown(): void
    {
        Plugin::forget();
        @unlink($this->appRoot . '/ProjectOnly.php');
        @rmdir($this->appRoot);

        if ($this->originalRoot !== null) {
            Kernel::$pathRoot = $this->originalRoot;
        }
    }

    public function test_packages_are_scanned_before_the_project(): void
    {
        Plugin::registry(self::REAL_PACKAGE);
        $recorder = new RecordingCollector();

        ClassScanner::scan($recorder);

        $project = array_search('WkScanFixture\\ProjectOnly', $recorder->seen, true);
        self::assertNotFalse($project, 'the project root has to be scanned at all');

        $fromPackage = array_filter(
            $recorder->seen,
            static fn(string $class): bool => str_starts_with($class, 'Flytachi\\Winter\\Base\\'),
        );
        self::assertNotEmpty($fromPackage, 'the package has to be scanned at all');

        self::assertLessThan(
            $project,
            max(array_keys($fromPackage)),
            'every class of the package comes before the project — the application applies last',
        );
    }

    public function test_the_project_is_scanned_even_with_no_packages(): void
    {
        $recorder = new RecordingCollector();

        ClassScanner::scan($recorder);

        self::assertContains('WkScanFixture\\ProjectOnly', $recorder->seen);
    }

    /**
     * The install directory itself is never a root, so a package's own `bootstrap.php`
     * and `resources/` never reach `require_once`.
     */
    public function test_a_package_is_scanned_through_its_declared_roots_only(): void
    {
        Plugin::registry(self::REAL_PACKAGE);

        $plugin = Plugin::all()[0];

        self::assertNotContains($plugin->path, $plugin->roots);
        foreach ($plugin->roots as $root) {
            self::assertDirectoryExists($root);
        }
    }

    // ── The server has one owner ──────────────────────────────────────────────

    private function refuse(array $found): void
    {
        new ReflectionMethod(WinterApplication::class, 'refuseServerConfigurer')->invoke(
            null,
            new PluginPackage('acme/billing', '/tmp/billing', '/billing', ['/tmp/billing/src']),
            $found,
        );
    }

    public function test_a_package_without_a_server_configurer_passes(): void
    {
        $this->refuse([]);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_server_configurer_inside_a_package_is_refused_by_name(): void
    {
        try {
            $this->refuse([new ReflectionClass(PretendTuning::class)]);
            self::fail('a package must not be able to move the bind address');
        } catch (ApplicationConfigException $e) {
            self::assertStringContainsString(PretendTuning::class, $e->getMessage());
            self::assertStringContainsString('acme/billing', $e->getMessage());
            self::assertStringContainsString('WebConfigurer', $e->getMessage(), 'say what it may do instead');
        }
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

final class RecordingCollector implements CollectorInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function collect(string $class, ReflectionClass $ref): void
    {
        $this->seen[] = $class;
    }
}

final class PretendTuning
{
}
