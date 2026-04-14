<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

abstract class KernelConfig
{
    public static string $pathRoot;
    public static string $pathEnv;
    public static string $pathPublic;
    public static string $pathResource;
    public static string $pathStorage;
    public static string $pathStorageLog;
    public static string $pathStorageCache;
    public static string $pathStorageRunnable;
    public static string $pathStorageVolatile;

    /**
     * @param string|null $pathRoot
     * @param string|null $pathEnv
     * @param string|null $pathPublic
     * @param string|null $pathResource
     * @param string|null $pathStorage
     * @param string|null $pathStorageLog
     * @param string|null $pathStorageCache
     * @param string|null $pathStorageRunnable
     * @param bool $isTmpVolatile
     * @return void
     */
    public static function init(
        ?string $pathRoot = null,
        ?string $pathEnv = null,
        ?string $pathPublic = null,
        ?string $pathResource = null,
        ?string $pathStorage = null,
        ?string $pathStorageLog = null,
        ?string $pathStorageCache = null,
        ?string $pathStorageRunnable = null,
        bool $isTmpVolatile = true
    ): void {
        // root
        if ($pathRoot === null) {
            $pathRoot = dirname(__DIR__, 5);
        }

        // env
        if ($pathEnv === null) {
            $pathEnv = $pathRoot . '/.env';
        }

        // public
        if ($pathPublic === null) {
            $pathPublic = $pathRoot . '/public';
        }

        // resource
        if ($pathStorageLog === null) {
            $pathResource = $pathRoot . '/resources';
        }

        // storage
        if ($pathStorage === null) {
            $pathStorage = $pathRoot . '/storage';
        }

        // storage log
        if ($pathStorageLog === null) {
            $pathStorageLog = $pathStorage . '/logs';
        }

        // storage cache
        if ($pathStorageCache === null) {
            $pathStorageCache = $pathStorage . '/cache';
        }

        // storage runnable
        if ($pathStorageRunnable === null) {
            $pathStorageRunnable = $pathStorage . '/runnable';
        }

        self::$pathRoot = $pathRoot;
        self::$pathEnv = $pathEnv;
        self::$pathPublic = $pathPublic;
        self::$pathResource = $pathResource;
        self::$pathStorage = $pathStorage;
        self::$pathStorageLog = $pathStorageLog;
        self::$pathStorageCache = $pathStorageCache;
        self::$pathStorageRunnable = $pathStorageRunnable;
        self::$pathStorageVolatile = self::changeVolatile($isTmpVolatile);
    }

    private static function changeVolatile(bool $isTmpVolatile): string
    {
        // storage volatile
        if ($isTmpVolatile) {
            $pathStorageVolatile = sys_get_temp_dir()
                . '/flytachi.winter.volatile.'
                . basename(self::$pathRoot);
        } else {
            $pathStorageVolatile = self::$pathStorage . '/volatile';
        }
        if (!is_dir($pathStorageVolatile)) {
            mkdir($pathStorageVolatile, 0777, true);
        }

        return $pathStorageVolatile;
    }
}
