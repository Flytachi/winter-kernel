<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\App\Attribute\EnableAsync;
use Flytachi\Winter\Kernel\App\Attribute\EnableCaching;
use Flytachi\Winter\Kernel\Cache\CacheConfigurer;
use Flytachi\Winter\Kernel\Cache\CacheInterceptor;
use Flytachi\Winter\Kernel\Cache\Caches;
use Flytachi\Winter\Kernel\Collector\ImplementorCollector;
use Flytachi\Winter\Kernel\Concurrent\Async\AsyncInterceptor;
use Flytachi\Winter\Kernel\Core\Proxy\BypassScanner;
use Flytachi\Winter\Kernel\Core\Proxy\MethodInterceptor;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyCollector;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyFactory;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyGenerator;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\WinterApplication;
use ReflectionClass;
use ReflectionMethod;

final class Di extends Cmd
{
    public static string $title = "manage and inspect DI scanner cache (build, clean, show, proxies)";

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
            'proxies', 'async' => $this->proxiesArg($this->args['arguments'][2] ?? ''),
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
     * Cache file location — kept in sync with WinterApplication::bootstrap(). The
     * cache is the FQCN class list, written by the Scanner independently of any
     * collector, so it is the same whichever collectors boot wires.
     */
    private static function cachePath(): string
    {
        return Kernel::$pathStorageVolatile . '/di.php';
    }

    /**
     * List of proxied classes — kept in sync with WinterApplication::bootstrap().
     */
    private static function proxyCachePath(): string
    {
        return Kernel::$pathStorageVolatile . '/proxy.php';
    }

    /**
     * Where releases before the shared proxy generator kept their artefacts; removed
     * on build and clean so an upgraded project does not keep stale files around.
     *
     * @return array{string, string} The class-list file and the proxy directory.
     */
    private static function legacyPaths(): array
    {
        return [Kernel::$pathStorageVolatile . '/async.php', Kernel::$pathStorageVolatile . '/async'];
    }

    /**
     * The interceptors the running app enables, mirroring the WinterApplication boot
     * decision: #[EnableAsync] and #[EnableCaching] on the app class. bootstrap() has
     * already run by the time this command dispatches, so the app class is known
     * without a scan. A non-WinterApplication entry sets no app class — there every
     * feature is on.
     *
     * @return list<MethodInterceptor>
     */
    private static function interceptors(): array
    {
        $app = WinterApplication::getAppClass();
        $ref = $app === '' ? null : new ReflectionClass($app);

        $interceptors = [];
        if ($ref === null || $ref->getAttributes(EnableAsync::class) !== []) {
            $interceptors[] = new AsyncInterceptor();
        }
        if ($ref === null || $ref->getAttributes(EnableCaching::class) !== []) {
            $interceptors[] = new CacheInterceptor();
        }

        return $interceptors;
    }

    private static function cachingEnabled(): bool
    {
        foreach (self::interceptors() as $interceptor) {
            if ($interceptor instanceof CacheInterceptor) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the artefacts of releases before the shared proxy generator.
     */
    private static function forgetLegacy(): void
    {
        [$list, $directory] = self::legacyPaths();
        self::forget($list);

        if (is_dir($directory)) {
            new ProxyFactory($directory, [])->clear();
            @rmdir($directory);
        }
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
     * The class list and the proxies come from the same scan on purpose — two
     * commands would leave a window where one is stale.
     *
     * Note that this step can fail on application code: an #[Async] or cache method
     * breaking its contract stops proxy generation, and a cache attribute naming an
     * undeclared cache fails the build. That is the point — the error belongs to CI,
     * not to the first request in production.
     *
     * @return bool False when an artefact could not be produced.
     */
    private function buildArg(): bool
    {
        $cachePath = self::cachePath();

        // Force a rebuild: both caches short-circuit when their file exists.
        self::forget($cachePath);
        self::forget(self::proxyCachePath());
        self::forgetLegacy();

        // Proxying is opt-in per feature (WinterApplication reads #[EnableAsync] and
        // #[EnableCaching] at boot); build the proxies only for what the app enables,
        // so `di build` mirrors boot. With neither, the proxy factory is not touched.
        $container    = Container::init();
        $interceptors = self::interceptors();
        $factory      = null;
        $proxies      = null;
        $configurers  = new ImplementorCollector(CacheConfigurer::class);
        if ($interceptors !== []) {
            $factory = ProxyFactory::forKernel($interceptors, refresh: true);
            $proxies = new ProxyCollector($container, $factory, self::proxyCachePath());
        }

        try {
            // Drop proxies of services that no longer exist or lost the attribute.
            if ($factory !== null) {
                $factory->clear();
            }

            // Same scan WinterApplication::bootstrap() makes — populates a fresh
            // Container and writes the FQCN list to $cachePath as a side effect. The
            // class list and the proxies come from the same scan on purpose: two
            // commands would leave a window where one is stale.
            $scan = ClassScanner::scanner(rootDir: Kernel::$pathRoot, cache: $cachePath)
                ->collect(new DICollector($container))
                ->collect($configurers);
            if ($proxies !== null) {
                $scan->collect($proxies);
            }
            $scan->execute();

            $proxies?->flush();
        } catch (\Throwable $e) {
            // The class list is written before collectors run, so report what did survive.
            $this->reportCache($cachePath);
            self::printBadge("proxies", 'FAILED', 34, 31);
            self::printWarning($e->getMessage());
            if (env('DEBUG', false)) {
                self::printSplit($e->getTraceAsString(), 31);
            }

            return false;
        }

        if (!$this->reportCache($cachePath)) {
            return false;
        }

        if ($proxies === null) {
            self::printBadge("proxies", 'DISABLED (no #[EnableAsync] or #[EnableCaching])', 34, 33);

            return true;
        }

        $proxied = $proxies->proxied();
        if (!$this->reportCaches($container, $configurers->getResult(), array_keys($proxied))) {
            return false;
        }

        if ($proxied === []) {
            self::printBadge("proxies", 'NONE', 34, 33);

            return true;
        }

        self::printBadge(
            "proxies",
            sprintf(
                'BUILT (%d classes, %d methods)',
                count($proxied),
                $this->countInterceptedMethods($proxied, $interceptors)
            ),
            34,
            32
        );
        if ($factory !== null) {
            self::printInfo($factory->directory());
        }

        $this->reportBypasses(array_keys($proxied));

        return true;
    }

    /**
     * Checks every cache attribute against the declared caches — the same check the
     * boot makes, so an undeclared name fails CI rather than the deployment.
     *
     * @param list<ReflectionClass> $configurers Classes implementing CacheConfigurer.
     * @param list<class-string> $proxied Classes swapped for proxies.
     * @return bool False when a cache is used but not declared.
     */
    private function reportCaches(Container $container, array $configurers, array $proxied): bool
    {
        if (!self::cachingEnabled()) {
            return true;
        }

        try {
            $declared = Caches::declare($container, $configurers, $proxied);
        } catch (\Throwable $e) {
            self::printBadge("caches", 'FAILED', 34, 31);
            self::printWarning($e->getMessage());

            return false;
        }

        self::printBadge(
            "caches",
            $declared === [] ? 'NONE DECLARED' : 'DECLARED (' . implode(', ', array_keys($declared)) . ')',
            34,
            $declared === [] ? 33 : 32
        );

        return true;
    }

    /**
     * Warns about services built with `new` instead of resolved from the container.
     *
     * Never fails the build — the scan is textual and cannot see dynamic
     * construction, so a clean report is not proof of correctness.
     *
     * @param list<class-string> $classes Classes that must come from the container.
     */
    private function reportBypasses(array $classes): void
    {
        $found = new BypassScanner($classes, self::bypassExcludes())->scan(Kernel::$pathRoot);

        if ($found === []) {
            self::printBadge("proxy bypass", 'NONE', 34, 32);
            return;
        }

        self::printBadge("proxy bypass", count($found) . ' FOUND', 34, 33);

        $shown = array_slice($found, 0, self::BYPASS_REPORT_LIMIT);
        foreach ($shown as $hit) {
            self::printWarning(sprintf(
                '%s:%d — new %s() bypasses the proxy, so its attributes do nothing; inject it instead',
                self::relativePath($hit['file']),
                $hit['line'],
                $hit['class']
            ));
        }

        $hidden = count($found) - count($shown);
        if ($hidden > 0) {
            self::printWarning("… and $hidden more (run `call di proxies` for the full service list)");
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
            self::forget(self::proxyCachePath());
            self::forgetLegacy();
            self::printBadge("di cache", $cleaned ? 'CLEANED' : 'NOT FOUND', 34, $cleaned ? 32 : 33);

            $removed = ProxyFactory::forKernel([])->clear();
            self::printBadge(
                "proxies",
                $removed > 0 ? "CLEANED ($removed files)" : 'NOT FOUND',
                34,
                $removed > 0 ? 32 : 33
            );
        } catch (\Throwable $e) {
            self::printWarning("Clean failed: " . $e->getMessage());
        }
    }

    /**
     * Lists every intercepted method found in the project and whether its proxy exists.
     *
     * @param string $pattern Case-insensitive FQCN substring filter.
     */
    private function proxiesArg(string $pattern): void
    {
        try {
            $interceptors = self::interceptors();
            $found        = $this->scanIntercepted($interceptors);
            $pattern      = trim($pattern);

            if ($pattern !== '') {
                $found = array_filter(
                    $found,
                    static fn(string $fqcn): bool => stripos($fqcn, $pattern) !== false,
                    ARRAY_FILTER_USE_KEY
                );
            }

            if ($found === []) {
                self::printWarning($pattern === ''
                    ? 'No proxied methods found.'
                    : "No proxied methods in classes matching '$pattern'.");
                return;
            }

            ksort($found);
            $factory = ProxyFactory::forKernel($interceptors);
            $label   = 'Proxied methods (' . count($found) . ' classes)';

            self::printLabel($label, 34);
            foreach ($found as $fqcn => $methods) {
                $built = is_file($factory->fileFor($fqcn));
                self::printBadge($fqcn, $built ? 'BUILT' : 'PENDING', 36, $built ? 32 : 33);
                foreach ($methods as [$name, $returns, $feature]) {
                    self::print("    {$name}() → {$returns}  [{$feature}]", 36);
                }
            }
            self::printLabel($label, 34);

            self::printDivider(34);
            self::printInfo($factory->directory());
        } catch (\Throwable $e) {
            self::printWarning("Proxy scan failed: " . $e->getMessage());
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
     * @param list<MethodInterceptor> $interceptors Enabled features.
     */
    private function countInterceptedMethods(array $proxied, array $interceptors): int
    {
        $total = 0;
        foreach (array_keys($proxied) as $class) {
            $total += count(ProxyGenerator::interceptedMethods(new ReflectionClass($class), $interceptors));
        }

        return $total;
    }

    /**
     * Walks the project and collects intercepted methods without generating anything.
     *
     * @param list<MethodInterceptor> $interceptors Enabled features.
     * @return array<class-string, list<array{string, string, string}>> Class mapped to method name, return type
     *     and feature.
     */
    private function scanIntercepted(array $interceptors): array
    {
        $sink = new class ($interceptors) implements CollectorInterface {
            /** @var array<class-string, list<array{string, string, string}>> */
            public array $found = [];

            /** @param list<MethodInterceptor> $interceptors */
            public function __construct(private readonly array $interceptors)
            {
            }

            public function collect(string $class, ReflectionClass $ref): void
            {
                if (str_starts_with($class, ProxyGenerator::PROXY_NAMESPACE . '\\')) {
                    return;
                }

                $methods = ProxyGenerator::interceptedMethods($ref, $this->interceptors);
                if ($methods === []) {
                    return;
                }

                $this->found[$class] = array_map(
                    static fn(array $claim): array => [
                        $claim[0]->getName(),
                        (string) ($claim[0]->getReturnType() ?? 'mixed'),
                        $claim[1]->name(),
                    ],
                    $methods
                );
            }
        };

        ClassScanner::scanner(rootDir: Kernel::$pathRoot)
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

        ClassScanner::scanner(rootDir: Kernel::$pathRoot)
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
        self::printBadge('build', 'scan project once: DI cache, proxies, cache names, bypass check', $cl, 36);
        self::printBadge('clean', 'delete the DI cache and every generated proxy', $cl, 36);
        self::printBadge('show', 'list every class in the DI cache', $cl, 36);
        self::printBadge('show <pattern>', 'filter cached classes by FQCN substring (case-insensitive)', $cl, 36);
        self::printBadge('proxies', 'list #[Async] and cache methods and whether their proxy is built', $cl, 36);
        self::printBadge('proxies <pattern>', 'filter by FQCN substring (case-insensitive)', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call di build");
        self::printInfo("call di clean");
        self::printInfo("call di show");
        self::printInfo("call di show App\\Service");
        self::printInfo("call di proxies");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Cache file: " . Kernel::$pathStorageVolatile . '/di.php');
        self::printInfo("Proxy dir:  " . Kernel::$pathStorageVolatile . '/' . ProxyFactory::DIRECTORY);
        self::printInfo("DEBUG=true disables the cache entirely (always live scan).");
        self::printInfo("build doubles as a contract check — an invalid #[Async] or cache method, or an");
        self::printInfo("undeclared cache name, fails here, not in prod.");
        self::printInfo("A failed build exits with code 1.");
        self::printInfo("It also warns when a proxied service is built with new (skips vendor/ and tests/).");
        self::printInfo("That check is textual: it cannot see 'new \$class' or factories, so it never fails a build.");

        self::printDivider($cl);

        self::printInfo("Docs: https://winterframe.net/docs/cmd-di");


        self::printTitle("Di Help", $cl);
    }
}
