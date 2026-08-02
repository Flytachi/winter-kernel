<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel;

use Composer\InstalledVersions;
use Flytachi\Winter\Kernel\Exception\Error;

final class Plugin
{
    /** @var array<string, string> [prefix => path] */
    private static array $plugins = [];

    private function __construct()
    {
    }

    public static function registry(string $package, string $prefix, bool $required = true): void
    {
        try {
            $path = InstalledVersions::getInstallPath($package);
        } catch (\OutOfBoundsException) {
            // Package is not installed at all.
            $path = null;
        }
        if ($path === null) {
            if ($required) {
                Error::throw("Plugin '$package' has no install path");
            }
            return;
        }

        $prefix = '/' . trim($prefix, '/');
        if (isset(self::$plugins[$prefix])) {
            Error::throw("Plugin prefix '$prefix' already registered");
        }

        self::$plugins[$prefix] = rtrim($path, '/\\');
    }

    /** @return array<string, string> */
    public static function getPlugins(): array
    {
        return self::$plugins;
    }
}
