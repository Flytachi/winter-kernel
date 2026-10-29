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
     * downstream workers (running as a non-privileged user) failed with
     * EACCES on every cache write.
     */
    public function test_init_does_not_create_the_build_directory(): void
    {
        KernelConfig::init(pathRoot: $this->tmpDir);

        self::assertSame($this->tmpDir . '/.winter/build', KernelConfig::$pathBuild);
        self::assertDirectoryDoesNotExist(
            KernelConfig::$pathBuild,
            'Kernel::init() must not create the build directory eagerly — '
            . 'creation is deferred to the moment of first use.'
        );
    }

    public function test_init_does_not_create_other_storage_directories(): void
    {
        KernelConfig::init(pathRoot: $this->tmpDir);

        self::assertDirectoryDoesNotExist(KernelConfig::$pathWinter);
        self::assertDirectoryDoesNotExist(KernelConfig::$pathRunnable);
        self::assertDirectoryDoesNotExist(KernelConfig::$pathLogs);
    }

    public function test_init_resolves_default_paths_under_root(): void
    {
        KernelConfig::init(pathRoot: $this->tmpDir);

        self::assertSame($this->tmpDir,                       KernelConfig::$pathRoot);
        self::assertSame($this->tmpDir . '/.env',             KernelConfig::$pathEnv);
        self::assertSame($this->tmpDir . '/resources',        KernelConfig::$pathResource);
        self::assertSame($this->tmpDir . '/logs',             KernelConfig::$pathLogs);
        self::assertSame($this->tmpDir . '/.winter',          KernelConfig::$pathWinter);
        self::assertSame($this->tmpDir . '/.winter/cache',    KernelConfig::$pathCache);
        self::assertSame($this->tmpDir . '/.winter/build',    KernelConfig::$pathBuild);
        self::assertSame($this->tmpDir . '/.winter/runnable', KernelConfig::$pathRunnable);
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

}
