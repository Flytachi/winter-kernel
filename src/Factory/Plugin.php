<?php

namespace Flytachi\Winter\Kernel\Factory;

use Composer\InstalledVersions;
use Flytachi\Winter\Kernel\Exception\Error;
use OutOfBoundsException;

final class Plugin
{
    /**
     * @var array<string, string>
     */
    private static array $plugins = [];

    private function __construct()
    {
    }

    public static function registry(string $folderPath, string $routePrefix): void
    {
        $prefix = trim($routePrefix, '/');
        $path = self::getPath($folderPath);
        if (isset(self::$plugins[$prefix])) {
            Error::throw("Route by prefix '$prefix' already registered");
        }
        self::$plugins[$prefix] = $path;
    }

    private static function getPath(string $folderPath): string
    {
        try {
            $path = InstalledVersions::getInstallPath($folderPath);
            if ($path === null) {
                Error::throw("Plugin '$folderPath' has no install path");
            }
            return $path;
        } catch (OutOfBoundsException) {
            if (!is_dir($folderPath)) {
                Error::throw("Plugin '$folderPath' not found");
            }
            return $folderPath;
        }
    }

    public static function getPlugins(): array
    {
        return self::$plugins;
    }
}
