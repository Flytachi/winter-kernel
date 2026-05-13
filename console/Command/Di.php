<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\K2\Kernel;
use ReflectionClass;

class Di extends Cmd
{
    public static string $title = "manage and inspect DI scanner cache (build, clean, show)";

    public function handle(): void
    {
        self::printTitle("Di", 34);

        $sub = $this->args['arguments'][1] ?? '';

        match ($sub) {
            'build' => $this->buildArg(),
            'clean' => $this->cleanArg(),
            'show'  => $this->showArg($this->args['arguments'][2] ?? ''),
            ''      => self::help(),
            default => $this->showArg($sub),
        };

        self::printTitle("Di", 34);
    }

    /**
     * Cache file location — kept in sync with BaseBoot::boot().
     */
    private static function cachePath(): string
    {
        return Kernel::$pathStorageVolatile . '/di.php';
    }

    private function showArg(string $pattern): void
    {
        try {
            $cachePath = self::cachePath();
            $live      = !is_file($cachePath);

            $classes = $live
                ? $this->scanLive()
                : (array) (require $cachePath);

            $pattern = trim($pattern);
            $matched = $pattern === ''
                ? $classes
                : array_values(array_filter(
                    $classes,
                    static fn(string $fqcn): bool =>
                        stripos($fqcn, $pattern) !== false
                ));

            sort($matched);
            $label = $pattern === '' ? 'Classes' : "Matched '$pattern'";

            if ($live) {
                self::printWarning("No DI cache file — showing live scan from " . Kernel::$pathRoot);
            } else {
                self::printInfo($cachePath);
            }

            if (empty($matched)) {
                self::printWarning("No DI classes matching '$pattern'.");
            } else {
                self::printLabel("$label (" . count($matched) . ")", 34);
                foreach ($matched as $fqcn) {
                    self::print($fqcn, 36);
                }
                self::printLabel($label, 34);
            }
        } catch (\Throwable $e) {
            self::printWarning("Show failed: " . $e->getMessage());
            if (env('DEBUG', false)) {
                self::printTitle($e->getMessage(), 31);
                self::printSplit($e->getTraceAsString(), 31);
                self::printTitle($e->getMessage(), 31);
            }
        }
    }

    private function buildArg(): void
    {
        try {
            $cachePath = self::cachePath();

            // Force a rebuild: Scanner short-circuits when the file already exists.
            if (file_exists($cachePath)) {
                @unlink($cachePath);
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($cachePath, true);
                }
            }

            // Same call BaseBoot::boot() makes — populates a fresh Container and
            // writes the FQCN list to $cachePath as a side effect.
            Scanner::run(rootDir: Kernel::$pathRoot, cache: $cachePath)
                ->collect(new DICollector(Container::init()))
                ->execute();

            if (!is_file($cachePath)) {
                self::printWarning("Cache file was not produced at $cachePath");
                return;
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($cachePath, true);
            }

            $count = count((array) (require $cachePath));
            self::printBadge("di cache", "BUILT ($count classes)", 34, 32);
            self::printInfo($cachePath);
        } catch (\Throwable $e) {
            self::printWarning("Build failed: " . $e->getMessage());
            if (env('DEBUG', false)) {
                self::printTitle($e->getMessage(), 31);
                self::printSplit($e->getTraceAsString(), 31);
                self::printTitle($e->getMessage(), 31);
            }
        }
    }

    private function cleanArg(): void
    {
        try {
            $cachePath = self::cachePath();
            if (file_exists($cachePath)) {
                unlink($cachePath);
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($cachePath, true);
                }
                self::printBadge("di cache", 'CLEANED', 34, 32);
            } else {
                self::printBadge("di cache", 'NOT FOUND', 34, 33);
            }
        } catch (\Throwable $e) {
            self::printWarning("Clean failed: " . $e->getMessage());
        }
    }

    /**
     * Walk the project filesystem without writing a cache file.
     * Returns the list of FQCNs the live cache would contain.
     *
     * @return string[]
     */
    private function scanLive(): array
    {
        $sink = new class implements CollectorInterface {
            /** @var string[] */
            public array $classes = [];

            public function collect(string $class, ReflectionClass $ref): void
            {
                $this->classes[] = $class;
            }
        };

        Scanner::run(rootDir: Kernel::$pathRoot)
            ->collect($sink)
            ->execute();

        return $sink->classes;
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Di Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call di <command> [pattern]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('build', 'scan project and write the DI cache file (deletes the existing one)', $cl, 36);
        self::printBadge('clean', 'delete the DI cache file', $cl, 36);
        self::printBadge('show', 'list every class in the DI cache', $cl, 36);
        self::printBadge('show <pattern>', 'filter cached classes by FQCN substring (case-insensitive)', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call di build");
        self::printInfo("call di clean");
        self::printInfo("call di show");
        self::printInfo("call di show App\\Service");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Cache file: " . Kernel::$pathStorageVolatile . '/di.php');
        self::printInfo("DEBUG=true disables the cache entirely (always live scan).");

        self::printTitle("Di Help", $cl);
    }
}
