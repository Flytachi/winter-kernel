<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Configuration;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\K2\Core\KernelStore;
use Flytachi\Winter\K2\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KernelStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/winter-kernelstore-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/cache');
        mkdir($this->tmpDir . '/runnable');
        mkdir($this->tmpDir . '/volatile');

        // KernelConfig holds path state in static properties — point them at our tempdir.
        $cfg                                   = new ReflectionClass(\Flytachi\Winter\K2\Core\KernelConfig::class);
        $cfg->getProperty('pathStorageCache')->setValue(null, $this->tmpDir . '/cache');
        $cfg->getProperty('pathStorageRunnable')->setValue(null, $this->tmpDir . '/runnable');
        $cfg->getProperty('pathStorageVolatile')->setValue(null, $this->tmpDir . '/volatile');

        // Reset KernelStore registries so each test starts with no cached instances.
        $store = new ReflectionClass(KernelStore::class);
        $store->getProperty('storages')->setValue(null, []);
        $store->getProperty('runnable')->setValue(null, []);
        $store->getProperty('volatiles')->setValue(null, []);
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

    // ── store() ──────────────────────────────────────────────────────────────

    public function test_store_returns_file_storage_instance(): void
    {
        $store = Kernel::store('users');
        self::assertInstanceOf(FileStorage::class, $store);
    }

    public function test_store_returns_same_instance_on_second_call(): void
    {
        $first  = Kernel::store('users');
        $second = Kernel::store('users');
        self::assertSame($first, $second);
    }

    public function test_store_returns_distinct_instances_for_distinct_names(): void
    {
        self::assertNotSame(Kernel::store('a'), Kernel::store('b'));
    }

    public function test_store_creates_parent_directory_if_missing(): void
    {
        $this->rmrf($this->tmpDir . '/cache');
        self::assertDirectoryDoesNotExist($this->tmpDir . '/cache');

        Kernel::store('users');
        self::assertDirectoryExists($this->tmpDir . '/cache');
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

    // ── volatile() ───────────────────────────────────────────────────────────

    public function test_volatile_returns_file_storage_instance(): void
    {
        self::assertInstanceOf(FileStorage::class, Kernel::volatile('routes'));
    }

    public function test_volatile_caches_instance(): void
    {
        self::assertSame(Kernel::volatile('routes'), Kernel::volatile('routes'));
    }

    public function test_volatile_creates_parent_directory_if_missing(): void
    {
        $this->rmrf($this->tmpDir . '/volatile');
        Kernel::volatile('routes');
        self::assertDirectoryExists($this->tmpDir . '/volatile');
    }

    // ── show*() reflectors ───────────────────────────────────────────────────

    public function test_show_storages_returns_only_created_instances(): void
    {
        self::assertSame([], Kernel::showStorages());

        Kernel::store('a');
        Kernel::store('b');

        $shown = Kernel::showStorages();
        self::assertCount(2, $shown);
        self::assertArrayHasKey('a', $shown);
        self::assertArrayHasKey('b', $shown);
    }

    public function test_show_runnable_returns_only_created_instances(): void
    {
        self::assertSame([], Kernel::showRunnable());
        Kernel::runnable('jobs');
        self::assertSame(['jobs'], array_keys(Kernel::showRunnable()));
    }

    public function test_show_volatiles_returns_only_created_instances(): void
    {
        self::assertSame([], Kernel::showVolatiles());
        Kernel::volatile('routes');
        self::assertSame(['routes'], array_keys(Kernel::showVolatiles()));
    }

    public function test_show_buckets_are_independent_per_call(): void
    {
        Kernel::store('cache_a');
        Kernel::runnable('job_b');
        Kernel::volatile('vol_c');

        self::assertSame(['cache_a'], array_keys(Kernel::showStorages()));
        self::assertSame(['job_b'],   array_keys(Kernel::showRunnable()));
        self::assertSame(['vol_c'],   array_keys(Kernel::showVolatiles()));
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

    public function test_volatile_helper_creates_path_with_0777(): void
    {
        // Reproduce the Docker scenario: storage/volatile does not exist yet,
        // and the process umask is restrictive. Without the fix the directory
        // would land at 0750 owned by whoever ran first.
        $this->rmrf($this->tmpDir . '/volatile');
        self::assertDirectoryDoesNotExist($this->tmpDir . '/volatile');

        $previousUmask = umask(0027);
        try {
            Kernel::volatile('routes');
        } finally {
            umask($previousUmask);
        }

        self::assertDirectoryExists($this->tmpDir . '/volatile');
        self::assertSame(0777, fileperms($this->tmpDir . '/volatile') & 0777);
    }
}
