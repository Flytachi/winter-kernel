<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Core;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\DI\Exception\ScanException;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The project's code is what its composer.json autoloads, plus the file declaring the
 * application class. Walking the whole root treated every `.php` under it as code: a test
 * class without its dev dependencies stopped the boot, a script's top-level code ran at
 * boot, and a folder nobody may open (a database volume) was fatal.
 */
final class ClassScannerRootsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wk-roots-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->root, 0777, true);
        Kernel::init(pathRoot: $this->root);
    }

    protected function tearDown(): void
    {
        ClassScanner::entry(null);
        @chmod($this->root . '/pgdata', 0755);
        @chmod($this->root . '/main/locked', 0755);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_only_the_autoloaded_code_and_the_entry_file_are_scanned(): void
    {
        $this->project();
        ClassScanner::entry($this->root . '/bootstrap.php');

        $collector = $this->collector();
        ClassScanner::scanner($this->root)->collect($collector)->execute();

        sort($collector->seen);
        self::assertSame(['RootsApp\Service', 'RootsEntryConfigurer'], $collector->seen);
        self::assertFileDoesNotExist($this->root . '/scripts/RAN', 'a script is not run at boot');
    }

    public function test_a_folder_nobody_may_open_is_skipped_and_named(): void
    {
        $this->project();
        mkdir($this->root . '/main/locked');
        file_put_contents($this->root . '/main/locked/Hidden.php', '<?php namespace RootsApp; class Hidden {}');
        chmod($this->root . '/main/locked', 0000);

        $collector = $this->collector();
        $scanner = ClassScanner::scanner($this->root)->collect($collector);
        $scanner->execute();

        self::assertSame(['RootsApp\Service'], $collector->seen);
        self::assertSame([$this->root . '/main/locked'], $scanner->skipped());
    }

    public function test_a_project_without_autoload_is_told_what_to_add(): void
    {
        file_put_contents($this->root . '/composer.json', '{"name":"x/y"}');

        $this->expectException(ScanException::class);
        $this->expectExceptionMessage('"autoload": {"psr-4": {"Main\\\\": "main/"}}');
        ClassScanner::scanner($this->root);
    }

    public function test_another_directory_is_scanned_as_it_is(): void
    {
        // Packages and fixtures: their own directory, no composer.json needed.
        mkdir($this->root . '/pkg');
        file_put_contents($this->root . '/pkg/Thing.php', '<?php namespace RootsPkg; class Thing {}');

        $collector = $this->collector();
        ClassScanner::scanner($this->root . '/pkg')->collect($collector)->execute();

        self::assertSame(['RootsPkg\Thing'], $collector->seen);
    }

    /**
     * The skeleton's layout plus what used to break the boot: tests without their dev
     * dependencies, a script with top-level code, a database volume nobody may open.
     */
    private function project(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            '{"autoload":{"psr-4":{"RootsApp\\\\":"main/"}},"autoload-dev":{"psr-4":{"RootsTests\\\\":"tests/"}}}'
        );
        $files = [
            'main/Service.php' => '<?php namespace RootsApp; class Service {}',
            'bootstrap.php' => '<?php class RootsEntryConfigurer {}',
            'tests/ServiceTest.php' => '<?php namespace RootsTests; class ServiceTest extends \NotInstalled\TestCase {}',
            'scripts/migrate.php' => "<?php file_put_contents(__DIR__ . '/RAN', 'x'); class RootsMigrate {}",
        ];
        foreach ($files as $path => $content) {
            @mkdir(dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, $content);
        }
        mkdir($this->root . '/pgdata');
        chmod($this->root . '/pgdata', 0000);
    }

    private function collector(): CollectorInterface
    {
        return new class implements CollectorInterface {
            /** @var list<string> */
            public array $seen = [];

            public function collect(string $class, ReflectionClass $ref): void
            {
                $this->seen[] = $class;
            }
        };
    }
}
