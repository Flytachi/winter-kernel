<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Plugin;

/**
 * Project-wide class discovery — runs a {@see Scanner} pass over the project's
 * code and every registered plugin, feeding each discovered class to the
 * given collectors.
 *
 * The project's code is what its `composer.json` autoloads (`main/` in the
 * skeleton), not everything under its root: tests, scripts and data folders are
 * no part of the application. Each plugin is scanned by its own roots.
 *
 * Usage:
 *   $collector = new ImplementorCollector(HealthContributor::class);
 *   ClassScanner::scan($collector);
 *   $refs = $collector->getResult(); // ReflectionClass[]
 */
final class ClassScanner
{
    /** The file declaring the application class — scanned with the project's code. */
    private static ?string $entry = null;

    private function __construct()
    {
    }

    /**
     * Names the file that declares the application class (`bootstrap.php` in the
     * skeleton). It is the application's code by definition, autoloaded or not, and a
     * configurer declared beside the application class must keep being found.
     *
     * @internal Called by {@see \Flytachi\Winter\Kernel\WinterApplication} at boot.
     */
    public static function entry(?string $file): void
    {
        self::$entry = $file;
    }

    /**
     * Scan every imported package and then the project, feeding each collector.
     *
     * The order is the point. Contributions that add up do not care, but anything where
     * a later contributor overwrites an earlier one does — and the application must be
     * the one that wins. It owns the process; a package should not overrule it by virtue
     * of having been walked first.
     *
     * Package roots come from each package's own `composer.json`; see
     * {@see Plugin::roots()} for why they are not guessed.
     */
    public static function scan(CollectorInterface ...$collectors): void
    {
        foreach (Plugin::roots() as $root) {
            self::run($root, $collectors);
        }

        self::run(Kernel::$pathRoot, $collectors);
    }

    /**
     * A {@see Scanner} carrying the project's standard exclusions.
     *
     * Use this instead of {@see Scanner::run()} anywhere the scan starts at the project
     * root. Given the root, it scans the directories the project's `composer.json`
     * autoloads ({@see Scanner::composer()}): walking the root itself treated every
     * `.php` under it as code — a test class without its dev dependencies stopped the
     * boot, a script's top-level code ran, and a folder nobody may open (a database
     * volume) was fatal. Given any other directory, it scans that directory.
     *
     * `Scanner` drops `vendor/` on its own; the two directories named here hold PHP that
     * is not application code, and matter when the project autoloads from its root
     * (`"App\\": ""`). The scanner cannot tell the difference — it reads every `.php`
     * file looking for a class declaration, then `require_once`s whatever it finds.
     *
     * - **.winter** holds *generated* code: the class list and the `#[Async]` /
     *   `#[Cacheable]` proxies are written to `.winter/build`, so scanning it is at best
     *   wasted work and at worst self-referential — a scan reading what the previous
     *   scan produced.
     * - **resources** holds *views*, which are PHP files by nature and classes by
     *   accident. A template that happens to declare a helper class matches the
     *   scanner's regex, gets required at boot, and **executes** — echoing into the
     *   output and running whatever else sits at its top level. Verified, not theorised.
     *   Even without a class declaration every template is read from disk on each cold
     *   scan for nothing.
     *
     * @param string|null $cache Cache file path, or null to always walk the filesystem.
     * @throws \Flytachi\Winter\DI\Exception\ScanException The project root has no
     *                                                       `composer.json` autoload.
     */
    public static function scanner(string $rootDir, ?string $cache = null): Scanner
    {
        if (!self::isProjectRoot($rootDir)) {
            return Scanner::run($rootDir, $cache)->exclude(self::excluded());
        }
        $roots = Scanner::composerRoots($rootDir);
        if (self::$entry !== null && is_file(self::$entry)) {
            $roots[] = self::$entry;
        }
        return Scanner::run($roots, $cache)->exclude(self::excluded());
    }

    private static function isProjectRoot(string $dir): bool
    {
        if (!isset(Kernel::$pathRoot)) {
            return false;
        }
        $root = realpath(Kernel::$pathRoot);
        return $root !== false && realpath($dir) === $root;
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
        // `.winter/` holds what the framework writes for itself — generated proxies among
        // it, which must never be read back as the project's code.
        return array_values(array_filter([
            isset(Kernel::$pathWinter) ? Kernel::$pathWinter : null,
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
