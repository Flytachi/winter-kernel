<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Core;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Plugin;

/**
 * Project-wide class discovery — runs a {@see Scanner} pass over the project
 * root and every registered plugin, feeding each discovered class to the
 * given collectors.
 *
 * Mirrors the scan pattern used by the router/DI bootstrap: the project root
 * (vendor excluded automatically) plus each plugin's `src` directory.
 *
 * Usage:
 *   $collector = new ImplementorCollector(Dispatchable::class);
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
     * @param CollectorInterface[] $collectors
     */
    private static function run(string $rootDir, array $collectors): void
    {
        $scanner = Scanner::run($rootDir);
        foreach ($collectors as $collector) {
            $scanner->collect($collector);
        }
        $scanner->execute();
    }
}
