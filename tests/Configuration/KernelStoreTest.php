<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Configuration;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Core\KernelStore;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KernelStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/winter-kernelstore-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/runnable');

        // KernelConfig holds path state in static properties — point them at our tempdir.
        $cfg                                   = new ReflectionClass(\Flytachi\Winter\Kernel\Core\KernelConfig::class);
        $cfg->getProperty('pathRunnable')->setValue(null, $this->tmpDir . '/runnable');

        // Reset KernelStore registries so each test starts with no cached instances.
        $store = new ReflectionClass(KernelStore::class);
        $store->getProperty('runnable')->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($full) ? $this->rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }

    // ── runnable() ───────────────────────────────────────────────────────────

    public function test_runnable_returns_file_storage_instance(): void
    {
        self::assertInstanceOf(FileStorage::class, Kernel::runnable('jobs'));
    }

    public function test_runnable_caches_instance(): void
    {
        self::assertSame(Kernel::runnable('jobs'), Kernel::runnable('jobs'));
    }

    public function test_runnable_creates_parent_directory_if_missing(): void
    {
        $this->rmrf($this->tmpDir . '/runnable');
        Kernel::runnable('jobs');
        self::assertDirectoryExists($this->tmpDir . '/runnable');
    }

    // ── show*() reflectors ───────────────────────────────────────────────────

    public function test_show_runnable_returns_only_created_instances(): void
    {
        self::assertSame([], Kernel::showRunnable());
        Kernel::runnable('jobs');
        self::assertSame(['jobs'], array_keys(Kernel::showRunnable()));
    }

    // ── ensureDirectory — Docker permissions guarantee ──────────────────────

    public function test_ensure_directory_creates_path_with_full_0777_mode(): void
    {
        $newDir = $this->tmpDir . '/created/by/ensure';
        self::assertDirectoryDoesNotExist($newDir);

        // Force a restrictive umask — without ensureDirectory's umask(0) wrapper
        // the resulting mode would be 0777 & ~0027 = 0750.
        $previousUmask = umask(0027);
        try {
            KernelStore::ensureDirectory($newDir);
        } finally {
            umask($previousUmask);
        }

        self::assertDirectoryExists($newDir);
        self::assertSame(0777, fileperms($newDir) & 0777);
    }

    public function test_ensure_directory_creates_intermediate_directories(): void
    {
        $deep = $this->tmpDir . '/a/b/c/d';
        KernelStore::ensureDirectory($deep);

        foreach (['/a', '/a/b', '/a/b/c', '/a/b/c/d'] as $sub) {
            self::assertDirectoryExists($this->tmpDir . $sub);
            self::assertSame(0777, fileperms($this->tmpDir . $sub) & 0777);
        }
    }

    public function test_ensure_directory_is_idempotent_on_existing_dir(): void
    {
        $existing = $this->tmpDir . '/already_here';
        mkdir($existing, 0750);
        $beforeMode = fileperms($existing) & 0777;

        KernelStore::ensureDirectory($existing);

        // Existing dir is left alone — we only set the mode on creation.
        self::assertSame($beforeMode, fileperms($existing) & 0777);
    }

    public function test_ensure_directory_silently_skips_empty_path(): void
    {
        // Common case: a config path is unset; calling with '' must not throw.
        KernelStore::ensureDirectory('');
        $this->expectNotToPerformAssertions();
    }

}
