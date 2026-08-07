<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Plugin;

/**
 * Project-wide class discovery — runs a {@see Scanner} pass over the project
 * root and every registered plugin, feeding each discovered class to the
 * given collectors.
 *
 * Mirrors the scan pattern used by the router/DI bootstrap: the project root
 * (vendor excluded automatically) plus each plugin's `src` directory.
 *
 * Usage:
 *   $collector = new ImplementorCollector(HealthContributor::class);
 *   ClassScanner::scan($collector);
 *   $refs = $collector->getResult(); // ReflectionClass[]
 */
final class ClassScanner
{
    private function __construct()
    {
    }

    /**
     * Scan the project root and all plugins, feeding every collector.
     */
    public static function scan(CollectorInterface ...$collectors): void
    {
        self::run(Kernel::$pathRoot, $collectors);

        foreach (Plugin::getPlugins() as $path) {
            $src = is_dir($path . '/src') ? $path . '/src' : $path;
            self::run($src, $collectors);
        }
    }

    /**
     * A {@see Scanner} carrying the project's standard exclusions.
     *
     * Use this instead of {@see Scanner::run()} anywhere the scan starts at the project
     * root. `Scanner` drops `vendor/` on its own; the two directories named here hold
     * PHP that is not application code, and the scanner cannot tell the difference — it
     * reads every `.php` file looking for a class declaration, then `require_once`s
     * whatever it finds.
     *
     * - **storage** holds *generated* code: the DI cache and the `#[Async]` proxies are
     *   written there, so scanning it is at best wasted work and at worst
     *   self-referential — a scan reading what the previous scan produced. Under the
     *   default `isTmpVolatile: true` it lives in the system temp directory and the scan
     *   never reaches it; under `false` it sits inside the project root.
     * - **resources** holds *views*, which are PHP files by nature and classes by
     *   accident. A template that happens to declare a helper class matches the
     *   scanner's regex, gets required at boot, and **executes** — echoing into the
     *   output and running whatever else sits at its top level. Verified, not theorised.
     *   Even without a class declaration every template is read from disk on each cold
     *   scan for nothing.
     *
     * @param string|null $cache Cache file path, or null to always walk the filesystem.
     */
    public static function scanner(string $rootDir, ?string $cache = null): Scanner
    {
        return Scanner::run($rootDir, $cache)->exclude(self::excluded());
    }

    /**
     * Directories excluded from every project scan, in addition to `vendor/`.
     *
     * @return list<string>
     */
    private static function excluded(): array
    {
        // Kernel::init() may not have run yet — a bare Scanner is still correct then,
        // it simply has fewer exclusions.
        return array_values(array_filter([
            isset(Kernel::$pathStorage) ? Kernel::$pathStorage : null,
            isset(Kernel::$pathResource) ? Kernel::$pathResource : null,
        ]));
    }

    /**
     * @param CollectorInterface[] $collectors
     */
    private static function run(string $rootDir, array $collectors): void
    {
        $scanner = self::scanner($rootDir);
        foreach ($collectors as $collector) {
            $scanner->collect($collector);
        }
        $scanner->execute();
    }
}
