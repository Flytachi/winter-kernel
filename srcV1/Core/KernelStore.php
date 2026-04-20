<?php

namespace Flytachi\Winter\Kernel\Core;

use Flytachi\FileStore\FileStorage;
use Flytachi\FileStore\FileStorageException;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
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
        if (!is_dir(self::$pathStorageCache)) {
            mkdir(self::$pathStorageCache, 0777, true);
        }
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
        if (!is_dir(self::$pathStorageRunnable)) {
            mkdir(self::$pathStorageRunnable, 0777, true);
        }
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
        if (!is_dir(self::$pathStorageVolatile)) {
            mkdir(self::$pathStorageVolatile, 0777, true);
        }
        if (!isset(self::$volatiles[$volName])) {
            self::$volatiles[$volName] = new FileStorage(self::$pathStorageVolatile, $volName, $isHash);
        }
        return self::$volatiles[$volName];
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
