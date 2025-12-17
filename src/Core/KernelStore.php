<?php

namespace Flytachi\Winter\Kernel\Core;

use Flytachi\FileStore\FileStorage;
use Flytachi\FileStore\FileStorageException;

abstract class KernelStore extends KernelConfig
{
    /* @var array<string, FileStorage> $storages */
    private static array $storages = [];

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
     * @return FileStorage[]
     */
    public static function showStorages(): array
    {
        return self::$storages;
    }
}
