<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Core;

use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The framework's own folder is never scanned.
 *
 * `Scanner` excludes `vendor/` on its own, but `.winter` is just as wrong to walk: it
 * holds generated code — the class list and the `#[Async]` / `#[Cacheable]` proxies in
 * `.winter/build` — so scanning it is self-referential. It sits inside the project root,
 * which is exactly where the scan reaches.
 */
final class ClassScannerExclusionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wk-scan-' . getmypid();
        @mkdir($this->root . '/.winter/build', 0777, true);
        Kernel::init(pathRoot: $this->root);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.winter/build/di.php');
        @rmdir($this->root . '/.winter/build');
        @rmdir($this->root . '/.winter');
        @rmdir($this->root);
    }

    public function test_the_winter_directory_is_excluded(): void
    {
        self::assertContains(
            rtrim(Kernel::$pathWinter, '/\\'),
            $this->exclusionsOf(ClassScanner::scanner($this->root)),
            '.winter holds the class list and generated proxies — walking it is self-referential.',
        );
    }

    /**
     * Views are PHP files that are not classes. The scanner reads every `.php` file
     * looking for a class declaration and `require_once`s what it finds, so a template
     * declaring a helper class would be **executed** at boot — echoing into the output
     * and running whatever else sits at its top level. Excluding the directory is the
     * only place that distinction can be made, since the scanner cannot see it.
     */
    public function test_the_resource_directory_is_excluded(): void
    {
        self::assertContains(
            rtrim(Kernel::$pathResource, '/\\'),
            $this->exclusionsOf(ClassScanner::scanner($this->root)),
            'resources/views holds templates — reading, let alone requiring them, is wrong.',
        );
    }

    public function test_vendor_stays_excluded_as_well(): void
    {
        self::assertContains(
            $this->root . '/vendor',
            $this->exclusionsOf(ClassScanner::scanner($this->root)),
            'Scanner excludes vendor itself; adding our own must not replace that.',
        );
    }

    /** @return list<string> */
    private function exclusionsOf(Scanner $scanner): array
    {
        return array_values(new ReflectionProperty(Scanner::class, 'exclude')->getValue($scanner));
    }
}
