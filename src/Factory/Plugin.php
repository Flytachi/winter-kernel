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

    public static function registry(string $package, string $route, bool $requared = true): void
    {
        $path = InstalledVersions::getInstallPath($package);
        if ($path === null) {
            if ($requared) {
                Error::throw("Plugin '$package' has no install path");
            } else {
                return;
            }
        }

        $prefix = trim($route, '/');
        if (isset(self::$plugins[$prefix])) {
            Error::throw("Route by prefix '$prefix' already registered");
        }
        self::$plugins[$prefix] = $path;
    }

    public static function getPlugins(): array
    {
        return self::$plugins;
    }
}
