<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\K2\Dev\Async\AsyncCollector;
use Flytachi\Winter\K2\Dev\Async\Proxy\BypassScanner;
use Flytachi\Winter\K2\Dev\Async\Proxy\ProxyFactory;
use Flytachi\Winter\K2\Dev\Async\Proxy\ProxyGenerator;
use Flytachi\Winter\K2\Kernel;
use ReflectionClass;
use ReflectionMethod;

class Di extends Cmd
{
    public static string $title = "manage and inspect DI scanner cache (build, clean, show, async)";

    /** Maximum bypass warnings printed before the rest is summarised. */
    private const int BYPASS_REPORT_LIMIT = 20;

    public function handle(): void
    {
        self::printTitle("Di", 34);

        $sub = $this->args['arguments'][1] ?? '';
        $ok  = true;

        match ($sub) {
            'build' => $ok = $this->buildArg(),
            'clean' => $this->cleanArg(),
            'show'  => $this->showArg($this->args['arguments'][2] ?? ''),
            'async' => $this->asyncArg($this->args['arguments'][2] ?? ''),
            ''      => self::help(),
            default => $this->showArg($sub),
        };

        self::printTitle("Di", 34);

        // A failed build must be visible to CI — the console layer otherwise
        // always exits 0.
        if (!$ok) {
            exit(1);
        }
    }

    /**
     * Cache file location — kept in sync with BaseBoot::boot().
     */
    private static function cachePath(): string
    {
        return Kernel::$pathStorageVolatile . '/di.php';
    }

    /**
     * List of classes carrying #[Async] — kept in sync with BaseBoot::boot().
     */
    private static function asyncCachePath(): string
    {
        return Kernel::$pathStorageVolatile . '/async.php';
    }

    /**
     * Removes a cache file and drops it from the opcode cache.
     */
    private static function forget(string $file): bool
    {
        if (!file_exists($file)) {
            return false;
        }

        @unlink($file);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        return true;
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

    /**
     * Builds every artefact the container needs, in a single filesystem pass.
     *
     * The class list and the #[Async] proxies come from the same scan on
     * purpose — two commands would leave a window where one is stale.
     *
     * Note that this step can now fail on application code: an #[Async] method
     * breaking its contract stops proxy generation. That is the point — the
     * error belongs to CI, not to the first request in production.
     *
     * @return bool False when an artefact could not be produced.
     */
    private function buildArg(): bool
    {
        $cachePath = self::cachePath();
        $factory   = ProxyFactory::forKernel(refresh: true);

        // Force a rebuild: both caches short-circuit when their file exists.
        self::forget($cachePath);
        self::forget(self::asyncCachePath());

        $async = new AsyncCollector(Container::init(), $factory, self::asyncCachePath());

        try {
            // Drop proxies of services that no longer exist or lost the attribute.
            $factory->clear();

            // Same call BaseBoot::boot() makes — populates a fresh Container and
            // writes the FQCN list to $cachePath as a side effect.
            Scanner::run(rootDir: Kernel::$pathRoot, cache: $cachePath)
                ->collect(new DICollector(Container::getInstance()))
                ->collect($async)
                ->execute();

            $async->flush();
        } catch (\Throwable $e) {
            // The class list is written before collectors run, so report what did survive.
            $this->reportCache($cachePath);
            self::printBadge("async proxies", 'FAILED', 34, 31);
            self::printWarning($e->getMessage());
            if (env('DEBUG', false)) {
                self::printSplit($e->getTraceAsString(), 31);
            }

            return false;
        }

        if (!$this->reportCache($cachePath)) {
            return false;
        }

        $proxied = $async->proxied();
        if ($proxied === []) {
            self::printBadge("async proxies", 'NONE', 34, 33);

            return true;
        }

        self::printBadge(
            "async proxies",
            sprintf('BUILT (%d classes, %d methods)', count($proxied), $this->countAsyncMethods($proxied)),
            34,
            32
        );
        self::printInfo($factory->directory());

        $this->reportBypasses(array_keys($proxied));

        return true;
    }

    /**
     * Warns about services built with `new` instead of resolved from the container.
     *
     * Never fails the build — the scan is textual and cannot see dynamic
     * construction, so a clean report is not proof of correctness.
     *
     * @param list<class-string> $asyncClasses Classes that must come from the container.
     */
    private function reportBypasses(array $asyncClasses): void
    {
        $found = new BypassScanner($asyncClasses, self::bypassExcludes())->scan(Kernel::$pathRoot);

        if ($found === []) {
            self::printBadge("async bypass", 'NONE', 34, 32);
            return;
        }

        self::printBadge("async bypass", count($found) . ' FOUND', 34, 33);

        $shown = array_slice($found, 0, self::BYPASS_REPORT_LIMIT);
        foreach ($shown as $hit) {
            self::printWarning(sprintf(
                '%s:%d — new %s() bypasses the proxy and runs synchronously; inject it instead',
                self::relativePath($hit['file']),
                $hit['line'],
                $hit['class']
            ));
        }

        $hidden = count($found) - count($shown);
        if ($hidden > 0) {
            self::printWarning("… and $hidden more (run `call di async` for the full service list)");
        }
    }

    /**
     * Directories the bypass scan skips: generated and non-application code.
     *
     * Test directories are excluded on purpose — constructing a service directly
     * is usually what a test wants.
     *
     * @return list<string>
     */
    private static function bypassExcludes(): array
    {
        return [
            Kernel::$pathStorage,
            Kernel::$pathStorageVolatile,
            Kernel::$pathRoot . '/tests',
            Kernel::$pathRoot . '/test',
        ];
    }

    /**
     * @param string $file Absolute path.
     */
    private static function relativePath(string $file): string
    {
        $root = rtrim(Kernel::$pathRoot, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private function cleanArg(): void
    {
        try {
            $cleaned = self::forget(self::cachePath());
            self::forget(self::asyncCachePath());
            self::printBadge("di cache", $cleaned ? 'CLEANED' : 'NOT FOUND', 34, $cleaned ? 32 : 33);

            $removed = ProxyFactory::forKernel()->clear();
            self::printBadge(
                "async proxies",
                $removed > 0 ? "CLEANED ($removed files)" : 'NOT FOUND',
                34,
                $removed > 0 ? 32 : 33
            );
        } catch (\Throwable $e) {
            self::printWarning("Clean failed: " . $e->getMessage());
        }
    }

    /**
     * Lists every #[Async] method found in the project and whether its proxy exists.
     *
     * @param string $pattern Case-insensitive FQCN substring filter.
     */
    private function asyncArg(string $pattern): void
    {
        try {
            $found   = $this->scanAsync();
            $pattern = trim($pattern);

            if ($pattern !== '') {
                $found = array_filter(
                    $found,
                    static fn(string $fqcn): bool => stripos($fqcn, $pattern) !== false,
                    ARRAY_FILTER_USE_KEY
                );
            }

            if ($found === []) {
                self::printWarning($pattern === ''
                    ? 'No #[Async] methods found.'
                    : "No #[Async] methods in classes matching '$pattern'.");
                return;
            }

            ksort($found);
            $factory = ProxyFactory::forKernel();
            $label   = 'Async methods (' . count($found) . ' classes)';

            self::printLabel($label, 34);
            foreach ($found as $fqcn => $methods) {
                $built = is_file($factory->fileFor($fqcn));
                self::printBadge($fqcn, $built ? 'BUILT' : 'PENDING', 36, $built ? 32 : 33);
                foreach ($methods as [$name, $returns]) {
                    self::print("    {$name}() → {$returns}", 36);
                }
            }
            self::printLabel($label, 34);

            self::printDivider(34);
            self::printInfo($factory->directory());
        } catch (\Throwable $e) {
            self::printWarning("Async scan failed: " . $e->getMessage());
            if (env('DEBUG', false)) {
                self::printSplit($e->getTraceAsString(), 31);
            }
        }
    }

    /**
     * Prints the state of the class-list cache.
     *
     * @param string $cachePath Absolute path of the cache file.
     * @return bool False when the file was not produced.
     */
    private function reportCache(string $cachePath): bool
    {
        if (!is_file($cachePath)) {
            self::printWarning("Cache file was not produced at $cachePath");
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cachePath, true);
        }

        $count = count((array) (require $cachePath));
        self::printBadge("di cache", "BUILT ($count classes)", 34, 32);
        self::printInfo($cachePath);

        return true;
    }

    /**
     * @param array<class-string, class-string> $proxied Original class mapped to its proxy.
     */
    private function countAsyncMethods(array $proxied): int
    {
        $total = 0;
        foreach (array_keys($proxied) as $class) {
            $total += count(ProxyGenerator::asyncMethods(new ReflectionClass($class)));
        }

        return $total;
    }

    /**
     * Walks the project and collects #[Async] methods without generating anything.
     *
     * @return array<class-string, list<array{string, string}>> Class mapped to method name and return type.
     */
    private function scanAsync(): array
    {
        $sink = new class implements CollectorInterface {
            /** @var array<class-string, list<array{string, string}>> */
            public array $found = [];

            public function collect(string $class, ReflectionClass $ref): void
            {
                if (str_starts_with($class, ProxyGenerator::PROXY_NAMESPACE . '\\')) {
                    return;
                }

                $methods = ProxyGenerator::asyncMethods($ref);
                if ($methods === []) {
                    return;
                }

                $this->found[$class] = array_map(
                    static fn(ReflectionMethod $m): array => [
                        $m->getName(),
                        (string) ($m->getReturnType() ?? 'mixed'),
                    ],
                    $methods
                );
            }
        };

        Scanner::run(rootDir: Kernel::$pathRoot)
            ->collect($sink)
            ->execute();

        return $sink->found;
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
        self::printBadge('build', 'scan project once: DI cache, #[Async] proxies, bypass check', $cl, 36);
        self::printBadge('clean', 'delete the DI cache and every generated proxy', $cl, 36);
        self::printBadge('show', 'list every class in the DI cache', $cl, 36);
        self::printBadge('show <pattern>', 'filter cached classes by FQCN substring (case-insensitive)', $cl, 36);
        self::printBadge('async', 'list #[Async] methods and whether their proxy is built', $cl, 36);
        self::printBadge('async <pattern>', 'filter by FQCN substring (case-insensitive)', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call di build");
        self::printInfo("call di clean");
        self::printInfo("call di show");
        self::printInfo("call di show App\\Service");
        self::printInfo("call di async");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Cache file: " . Kernel::$pathStorageVolatile . '/di.php');
        self::printInfo("Proxy dir:  " . Kernel::$pathStorageVolatile . '/' . ProxyFactory::DIRECTORY);
        self::printInfo("DEBUG=true disables the cache entirely (always live scan).");
        self::printInfo("build doubles as a contract check — an invalid #[Async] method fails here, not in prod.");
        self::printInfo("A failed build exits with code 1.");
        self::printInfo("It also warns when an #[Async] service is built with new (skips vendor/ and tests/).");
        self::printInfo("That check is textual: it cannot see 'new \$class' or factories, so it never fails a build.");

        self::printTitle("Di Help", $cl);
    }
}
