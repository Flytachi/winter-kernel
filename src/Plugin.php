<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel;

use Composer\InstalledVersions;
use Flytachi\Winter\Kernel\App\PluginPackage;
use Flytachi\Winter\Kernel\Exception\Error;

/**
 * The packages this application imported, in declaration order.
 *
 * Filled from {@see \Flytachi\Winter\Kernel\App\Attribute\Import} before the boot scan,
 * and read by everyone who needs a package's code: the scan itself, the router, the
 * database commands.
 *
 * @link https://winterframe.net/docs/packages Packages
 */
final class Plugin
{
    /** @var list<PluginPackage> In the order the #[Import] attributes were declared. */
    private static array $plugins = [];

    private function __construct()
    {
    }

    /**
     * Resolves a package and adds it to the registry.
     *
     * @param string $package Composer name, e.g. `acme/billing`.
     * @param string|null $prefix URL prefix for its controllers; null mounts no routes.
     * @param bool $required Fail when the package is not installed.
     * @return PluginPackage|null The registered package, or null when an optional one
     *                            was absent — so the caller can say which of the two
     *                            happened instead of guessing.
     */
    public static function registry(
        string $package,
        ?string $prefix = null,
        bool $required = true,
    ): ?PluginPackage {
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
            return null;
        }

        $path = rtrim($path, '/\\');

        if ($prefix !== null) {
            // Whitespace is stripped alongside the slashes: a prefix of spaces normalises
            // to nothing useful either, and reaches the router as '/%20'.
            $trimmed = trim($prefix, "/ \t\n\r\0\x0B");

            // '' and '/' both normalise to '/', and a route built on it comes out as
            // `//users` — a path no request matches, mounted silently. Since null already
            // says "import without routes", these values have no meaning left to carry.
            if ($trimmed === '') {
                Error::throw(
                    "Plugin '$package': prefix '$prefix' is not a mount point — every "
                    . "route would start with '//' and never match a request. Pass a real "
                    . "prefix such as '/billing', or omit it to import the package "
                    . 'without mounting its routes.'
                );
            }

            $prefix = '/' . $trimmed;
            foreach (self::$plugins as $registered) {
                if ($registered->prefix === $prefix) {
                    Error::throw("Plugin prefix '$prefix' already registered");
                }
            }
        }

        return self::$plugins[] = new PluginPackage(
            $package,
            $path,
            $prefix,
            self::rootsOf($package, $path),
        );
    }

    /** @return list<PluginPackage> */
    public static function all(): array
    {
        return self::$plugins;
    }

    /**
     * Every directory holding imported code, in import order — what the boot scan walks
     * in addition to the project root.
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        return array_merge(...array_map(
            static fn(PluginPackage $plugin): array => $plugin->roots,
            self::$plugins,
        )) ?: [];
    }

    /** @return list<PluginPackage> Only the packages that mount routes. */
    public static function routed(): array
    {
        return array_values(array_filter(
            self::$plugins,
            static fn(PluginPackage $plugin): bool => $plugin->mountsRoutes(),
        ));
    }

    /** Drops the registry. For tests and for a worker rebuilding its state. */
    public static function forget(): void
    {
        self::$plugins = [];
    }

    /**
     * Where a package keeps its own classes, according to the package itself.
     *
     * Taken from its `composer.json` rather than guessed, because guessing has to pick a
     * convention and every wrong guess is expensive in a different way. A package laid
     * out as `main/` was skipped entirely by one caller and scanned from its root by
     * another — and scanning a package root means handing `require_once` its `resources/`
     * templates, which execute, and its `bootstrap.php`, whose `Application` class
     * collides with the host's. Composer already knows the answer.
     *
     * @return list<string> Absolute, existing directories.
     */
    private static function rootsOf(string $package, string $path): array
    {
        $manifest = $path . '/composer.json';
        if (!is_file($manifest)) {
            Error::throw("Plugin '$package' has no composer.json at '$path'");
        }

        $json = json_decode((string) file_get_contents($manifest), true);
        $psr4 = $json['autoload']['psr-4'] ?? [];

        $roots = [];
        foreach ($psr4 as $dirs) {
            foreach ((array) $dirs as $dir) {
                $root = rtrim($path . '/' . trim((string) $dir, '/\\'), '/\\');
                if (is_dir($root) && !in_array($root, $roots, true)) {
                    $roots[] = $root;
                }
            }
        }

        if ($roots === []) {
            Error::throw(
                "Plugin '$package' declares no autoload.psr-4 in its composer.json, "
                . 'so there is no code for winter to scan.'
            );
        }

        return $roots;
    }
}
