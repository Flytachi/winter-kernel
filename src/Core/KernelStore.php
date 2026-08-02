<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

use Flytachi\FileStore\FileStorage;
use Flytachi\FileStore\FileStorageException;

abstract class KernelStore extends KernelConfig
{
    /* @var array<string, FileStorage> $storages */
    private static array $storages = [];

    /* @var array<string, FileStorage> $runnable */
    private static array $runnable = [];

    /* @var array<string, FileStorage> $volatiles */
    private static array $volatiles = [];

    /**
     * @throws FileStorageException
     */
    public static function store(string $storeName, bool $isHash = true): FileStorage
    {
        self::ensureDirectory(self::$pathStorageCache);
        if (!isset(self::$storages[$storeName])) {
            self::$storages[$storeName] = new FileStorage(self::$pathStorageCache, $storeName, $isHash);
        }
        return self::$storages[$storeName];
    }

    /**
     * @throws FileStorageException
     */
    public static function runnable(string $runName, bool $isHash = true): FileStorage
    {
        self::ensureDirectory(self::$pathStorageRunnable);
        if (!isset(self::$runnable[$runName])) {
            self::$runnable[$runName] = new FileStorage(self::$pathStorageRunnable, $runName, $isHash);
        }
        return self::$runnable[$runName];
    }

    /**
     * @throws FileStorageException
     */
    public static function volatile(string $volName, bool $isHash = true): FileStorage
    {
        self::ensureDirectory(self::$pathStorageVolatile);
        if (!isset(self::$volatiles[$volName])) {
            self::$volatiles[$volName] = new FileStorage(self::$pathStorageVolatile, $volName, $isHash);
        }
        return self::$volatiles[$volName];
    }

    /**
     * Idempotently create a directory with mode 0777.
     *
     * `mkdir(0777, true)` on its own is filtered by the current process umask
     * (typically 0022 → 0755). That is fine for app-level files, but the storage
     * tree is shared between any process that can boot the kernel — FPM workers,
     * console maintenance commands, Thread executor children, and operator
     * scripts run from a shell. They may run as different users (especially in
     * Docker images that scaffold during `RUN` as root and then drop privileges
     * for runtime). Locking the mode at 0777 lets every later writer succeed
     * regardless of which user created the directory first.
     *
     * Pass an empty string to skip — convenient for callers that hold a path
     * that is not always set.
     */
    public static function ensureDirectory(string $path): void
    {
        if ($path === '' || is_dir($path)) {
            return;
        }
        $previous = umask(0);
        try {
            // suppress warnings — a concurrent boot may race us, is_dir below decides
            @mkdir($path, 0777, true);
        } finally {
            umask($previous);
        }
        if (!is_dir($path)) {
            throw new FileStorageException("Failed to create directory: $path");
        }
    }

    /**
     * @return FileStorage[]
     */
    public static function showStorages(): array
    {
        return self::$storages;
    }

    /**
     * @return FileStorage[]
     */
    public static function showRunnable(): array
    {
        return self::$runnable;
    }

    /**
     * @return FileStorage[]
     */
    public static function showVolatiles(): array
    {
        return self::$volatiles;
    }
}
