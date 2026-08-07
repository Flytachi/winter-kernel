<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Configuration;

use Flytachi\Winter\Kernel\Core\KernelConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KernelConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/winter-kernelconfig-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
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
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($full) ? $this->rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }

    /**
     * Regression: Kernel::init() used to mkdir() the volatile directory
     * eagerly, even when the calling command never touched storage. In Docker
     * builds that left the directory owned by root with mode 0755 and
     * downstream FPM workers (running as a non-privileged user) failed with
     * EACCES on every Job dispatch / route-cache write.
     */
    public function test_init_does_not_create_volatile_directory(): void
    {
        $expectedVolatile = $this->tmpDir . '/storage/volatile';

        KernelConfig::init(
            pathRoot:    $this->tmpDir,
            pathStorage: $this->tmpDir . '/storage',
            // isTmpVolatile defaults to true on KernelConfig, but the Kernel
            // wrapper passes false. We explicitly pass false here to mimic the
            // production-FPM scenario where the bug bit hardest.
            isTmpVolatile: false,
        );

        self::assertSame($expectedVolatile, KernelConfig::$pathStorageVolatile);
        self::assertDirectoryDoesNotExist(
            $expectedVolatile,
            'Kernel::init() must not create the volatile directory eagerly — '
            . 'creation is deferred to KernelStore::ensureDirectory() at the moment of first use.'
        );
    }

    public function test_init_does_not_create_other_storage_directories(): void
    {
        KernelConfig::init(
            pathRoot:    $this->tmpDir,
            pathStorage: $this->tmpDir . '/storage',
        );

        self::assertDirectoryDoesNotExist(KernelConfig::$pathStorageCache);
        self::assertDirectoryDoesNotExist(KernelConfig::$pathStorageRunnable);
        self::assertDirectoryDoesNotExist(KernelConfig::$pathStorageLog);
    }

    public function test_init_resolves_default_paths_under_root(): void
    {
        KernelConfig::init(pathRoot: $this->tmpDir);

        self::assertSame($this->tmpDir,                       KernelConfig::$pathRoot);
        self::assertSame($this->tmpDir . '/.env',             KernelConfig::$pathEnv);
        self::assertSame($this->tmpDir . '/resources',        KernelConfig::$pathResource);
        self::assertSame($this->tmpDir . '/storage',          KernelConfig::$pathStorage);
        self::assertSame($this->tmpDir . '/storage/logs',     KernelConfig::$pathStorageLog);
        self::assertSame($this->tmpDir . '/storage/cache',    KernelConfig::$pathStorageCache);
        self::assertSame($this->tmpDir . '/storage/runnable', KernelConfig::$pathStorageRunnable);
    }

    /**
     * `$pathResource` used to be derived under `if ($pathStorageLog === null)`, so
     * passing a log path alone left it unassigned and init died on the typed property.
     */
    public function test_an_explicit_log_path_does_not_break_resource_resolution(): void
    {
        KernelConfig::init(
            pathRoot:       $this->tmpDir,
            pathStorageLog: $this->tmpDir . '/var/log',
        );

        self::assertSame($this->tmpDir . '/resources', KernelConfig::$pathResource);
        self::assertSame($this->tmpDir . '/var/log',   KernelConfig::$pathStorageLog);
    }

    /** The mirror case: an explicit resource path used to be silently overwritten. */
    public function test_an_explicit_resource_path_is_kept(): void
    {
        KernelConfig::init(
            pathRoot:     $this->tmpDir,
            pathResource: $this->tmpDir . '/app/views',
        );

        self::assertSame($this->tmpDir . '/app/views', KernelConfig::$pathResource);
    }

    public function test_volatile_path_uses_temp_dir_when_isTmpVolatile_is_true(): void
    {
        KernelConfig::init(pathRoot: $this->tmpDir, isTmpVolatile: true);
        $expected = sys_get_temp_dir() . '/flytachi.winter.volatile.' . basename($this->tmpDir);
        self::assertSame($expected, KernelConfig::$pathStorageVolatile);
    }

    public function test_volatile_path_uses_storage_subdir_when_isTmpVolatile_is_false(): void
    {
        KernelConfig::init(
            pathRoot: $this->tmpDir,
            pathStorage: $this->tmpDir . '/storage',
            isTmpVolatile: false,
        );
        self::assertSame($this->tmpDir . '/storage/volatile', KernelConfig::$pathStorageVolatile);
    }
}
