<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Core;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Core\ScanCache;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Who writes the class-list cache. Any first command with `DEBUG=false` used to write it —
 * `php call` included — and nothing ever rewrote it: a class added afterwards stayed
 * invisible to the next `call run`, and `call run dev` restarted on the new file and still
 * answered 404 for its routes.
 */
final class ClassScannerCacheTest extends TestCase
{
    private string $root;

    private string $ns;

    private mixed $debug;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wk-scancache-' . getmypid() . '-' . bin2hex(random_bytes(3));
        // Classes cannot be declared twice in one process: each test gets its own namespace.
        $this->ns = 'ScanCache' . bin2hex(random_bytes(4));
        mkdir($this->root . '/main', 0777, true);
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['autoload' => ['psr-4' => [$this->ns . '\\' => 'main/']]])
        );
        $this->debug = $_ENV['DEBUG'] ?? null;
        unset($_ENV['DEBUG']);
        Kernel::init(pathRoot: $this->root);
        mkdir(Kernel::$pathBuild, 0777, true);
        $this->addClass('First');
    }

    protected function tearDown(): void
    {
        ClassScanner::cacheMode(ScanCache::Read);
        if ($this->debug === null) {
            unset($_ENV['DEBUG']);
        } else {
            $_ENV['DEBUG'] = $this->debug;
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_a_command_never_creates_the_list(): void
    {
        ClassScanner::cacheMode(ScanCache::Read);

        self::assertNull(ClassScanner::cachePath());
        $this->scan(ClassScanner::cachePath());

        self::assertFileDoesNotExist($this->cacheFile());
    }

    public function test_a_command_reads_the_list_a_start_wrote(): void
    {
        ClassScanner::cacheMode(ScanCache::Refresh);
        $this->scan(ClassScanner::cachePath());

        ClassScanner::cacheMode(ScanCache::Read);
        $this->addClass('Second');

        self::assertSame($this->cacheFile(), ClassScanner::cachePath());
        self::assertSame([$this->ns . '\First'], $this->scan(ClassScanner::cachePath()), 'read, not walked');
    }

    public function test_a_start_replaces_a_list_left_behind(): void
    {
        $this->staleList();
        ClassScanner::cacheMode(ScanCache::Refresh);

        $seen = $this->scan(ClassScanner::cachePath());

        self::assertSame([$this->ns . '\First'], $seen);
        self::assertSame([$this->ns . '\First'], require $this->cacheFile());
    }

    public function test_what_scans_after_the_start_reads_its_list(): void
    {
        ClassScanner::cacheMode(ScanCache::Refresh);
        $this->scan(ClassScanner::cachePath());
        $this->addClass('Second');

        // The router and the health report: the path again, not a second walk.
        self::assertSame($this->cacheFile(), ClassScanner::cachePath());
        self::assertSame([$this->ns . '\First'], $this->scan(ClassScanner::cachePath()));
    }

    public function test_run_dev_walks_every_time_and_drops_the_list(): void
    {
        $this->staleList();
        ClassScanner::cacheMode(ScanCache::Live);

        self::assertNull(ClassScanner::cachePath());
        self::assertFileDoesNotExist($this->cacheFile());

        $this->addClass('Second');
        $seen = $this->scan(ClassScanner::cachePath());
        sort($seen);
        self::assertSame([$this->ns . '\First', $this->ns . '\Second'], $seen);
        self::assertFileDoesNotExist($this->cacheFile());
    }

    public function test_debug_walks_and_leaves_the_file_alone(): void
    {
        $this->staleList();
        $_ENV['DEBUG'] = 'true';

        foreach ([ScanCache::Read, ScanCache::Refresh, ScanCache::Live] as $mode) {
            ClassScanner::cacheMode($mode);
            self::assertNull(ClassScanner::cachePath(), $mode->name);
        }
        self::assertFileExists($this->cacheFile());
    }

    private function cacheFile(): string
    {
        return Kernel::$pathBuild . '/di.php';
    }

    /** A list from an older run of the code: a class that is gone, none of today's. */
    private function staleList(): void
    {
        file_put_contents($this->cacheFile(), "<?php return ['Gone\\\\Service'];");
    }

    private function addClass(string $name): void
    {
        file_put_contents(
            "{$this->root}/main/{$name}.php",
            "<?php namespace {$this->ns}; final class {$name} {}"
        );
    }

    /** @return list<string> The classes the scan handed to its collector. */
    private function scan(?string $cache): array
    {
        $collector = new class implements CollectorInterface {
            /** @var list<string> */
            public array $seen = [];

            public function collect(string $class, ReflectionClass $ref): void
            {
                $this->seen[] = $class;
            }
        };
        ClassScanner::scanner($this->root, $cache)->collect($collector)->execute();

        return $collector->seen;
    }
}
