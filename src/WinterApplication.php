<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Base\RuntimeMode;
use Flytachi\Winter\Console\Core;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\Attribute\EnableActuator;
use Flytachi\Winter\Kernel\App\Attribute\EnableAsync;
use Flytachi\Winter\Kernel\App\Attribute\EnableCaching;
use Flytachi\Winter\Kernel\App\Attribute\EnableDaemon;
use Flytachi\Winter\Kernel\App\Attribute\EnableProcess;
use Flytachi\Winter\Kernel\App\Attribute\EnableScheduler;
use Flytachi\Winter\Kernel\App\Attribute\EnableHttp;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\App\Attribute\EnableWebSocket;
use Flytachi\Winter\Kernel\App\Attribute\Import;
use Flytachi\Winter\Kernel\App\Banner;
use Flytachi\Winter\Kernel\App\BindAddress;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\App\Supervision\ComponentControl;
use Flytachi\Winter\Kernel\App\Supervision\ComponentRecords;
use Flytachi\Winter\Kernel\App\Supervision\Supervisor;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Components;
use Flytachi\Winter\Kernel\App\HttpTier;
use Flytachi\Winter\Kernel\App\ListenFlags;
use Flytachi\Winter\Kernel\App\Supervision\ComponentChannel;
use Flytachi\Winter\Kernel\App\ContributionCollector;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\App\Config\ChannelRegistry;
use Flytachi\Winter\Kernel\App\Config\CorsRegistry;
use Flytachi\Winter\Kernel\App\Config\LoggingConfigurer;
use Flytachi\Winter\Kernel\App\Config\ServerConfigurer;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\App\Config\WebConfigurer;
use Flytachi\Winter\Kernel\App\Config\WorkerMemory;
use Flytachi\Winter\Kernel\App\PluginPackage;
use Flytachi\Winter\Kernel\Collector\ConfigurationCollector;
use Flytachi\Winter\Kernel\Collector\ImplementorCollector;
use Flytachi\Winter\Kernel\Collector\ScopeGraphCollector;
use Flytachi\Winter\Kernel\Cache\CacheConfigurer;
use Flytachi\Winter\Kernel\Cache\CacheException;
use Flytachi\Winter\Kernel\Cache\CacheInterceptor;
use Flytachi\Winter\Kernel\Cache\CacheManager;
use Flytachi\Winter\Kernel\Cache\Caches;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Concurrent\Async\AsyncInterceptor;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyCollector;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyFactory;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleResponse;
use Flytachi\Winter\Kernel\Http\Health\Health;
use Flytachi\Winter\Kernel\Http\Health\HealthContributor;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;
use Flytachi\Winter\Kernel\Plugin;
use Flytachi\Winter\Kernel\Process\BeforeFork;
use Flytachi\Winter\Kernel\Process\ForkReset;
use Flytachi\Winter\Kernel\Route\DevWatcher;
use Flytachi\Winter\Kernel\Route\RequestWatchdog;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\WebSocket\WebSocket;
use Flytachi\Winter\Logger\Context\CoroutineContext;
use Flytachi\Winter\Logger\Context\ProcessContext;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Thread\Runner\AdaptiveRunner;
use Psr\Log\LoggerInterface;

/**
 * Single application entry point — the framework's answer to a Java `main()`,
 * built around scanner-discovered configuration (Spring-style), with no
 * "god bootstrap class".
 *
 * Extend it once, declare what the application contains with #[Enable*] attributes,
 * then route the CLI through {@see main()} from a single file:
 * ```
 * #[EnableHttp]
 * #[EnableAsync]
 * #[EnableCaching]
 * #[EnableScheduler]
 * #[EnableProcess(SnmpProc::class)]
 * #[EnableDaemon(Emails::class)]
 * #[Import('acme/auth-plugin', '/auth')]
 * final class App extends WinterApplication
 * {
 *     public static function main(array $args): never
 *     {
 *         return self::run($args);
 *     }
 * }
 *
 * // call — the one entry:
 * App::main($argv);
 * ```
 *
 * The manifest is declarative: each #[Enable*] on the App class maps to one
 * {@see Component} ({@see EnableHttp} → http, {@see EnableProcess}/{@see EnableDaemon}
 * → workers, {@see EnableScheduler} → scheduler), except {@see EnableAsync} and
 * {@see EnableCaching}, which only toggle proxying during boot.
 *
 * Configuration is not a set of hook methods on this class; it lives in ordinary
 * classes the scanner finds:
 *   - {@see App\Attribute\Configuration}/{@see App\Attribute\Bean} — DI factories;
 *   - {@see WebConfigurer} — CORS + Swoole server tuning (host/port);
 *   - {@see LoggingConfigurer} — extra log channels;
 *   - {@see Import} attributes — plugin packages.
 *
 * `main()`/`run()` boot once, then dispatch: `run` / `run dev` brings the
 * application up ({@see serve()}); a bare invocation or any other verb (`make`,
 * `daemon`, `schedule`, ...) is handed to the console dispatcher (bare → help).
 */
abstract class WinterApplication
{
    private static string $appClass = '';
    private static ?Container $container = null;
    /** Monotonic boot start (hrtime ns), for the startup banner's "up in N ms". */
    private static int $bootStartedAt = 0;
    /** @var list<class-string<WebConfigurer>> */
    private static array $webConfigurers = [];

    /** The single ServerConfigurer of the application, if it declared one. */
    private static ?string $serverConfigurer = null;

    /** Returns the concrete application class name set during boot. */
    public static function getAppClass(): string
    {
        return self::$appClass;
    }

    // ── Hooks (override in your App class) ────────────────────────────────────

    /**
     * Initialise the kernel — paths, .env, logging, timezone. Runs before the
     * scan (it decides where the scan looks), so it cannot be a discovered class.
     *
     * The default derives the project root from the App class's own file; override
     * only for non-standard layouts.
     */
    protected static function configure(ApplicationArguments $args): void
    {
        Kernel::init(pathRoot: static::rootPath());
    }

    // ── Entry ─────────────────────────────────────────────────────────────────

    /**
     * The application's `main()` — the single front door. Typically the App class
     * just forwards to {@see run()}:
     * ```
     * public static function main(array $argv): never { static::run($argv); }
     * ```
     *
     * @param array $argv Raw $argv (script name in [0]).
     */
    public static function main(array $argv): never
    {
        static::run($argv);
    }

    /**
     * The background-launch entry — the child side of {@see Process::dispatch()}.
     *
     * Detaching a process cannot be a fork: the parent may be a Swoole worker whose
     * reactor must not be duplicated, so the launcher spawns a **fresh PHP process**
     * running `vendor/bin/wKernelRunner`, which lands here. The application has to be
     * booted again from scratch — a new process shares nothing — before the staged
     * payload can run, otherwise the dispatched class has no container, no logging
     * and no configuration.
     *
     * So this is {@see run()} without the dispatch: the same {@see bootstrap()},
     * then the thread payload instead of the console. The launcher's own options
     * (`--namespace`, `--name`, `--tag`, `--debug`, `--detach`, `--shmkey`) are read
     * straight from the command line by `getopt()`; `--detach` makes the runner
     * daemonise itself.
     *
     * @param array $argv Raw $argv (script name in [0]).
     */
    final public static function executor(array $argv): never
    {
        try {
            static::bootstrap(ApplicationArguments::parse($argv));
        } catch (\Throwable $e) {
            static::bootFailed($e);
        }

        exit(AdaptiveRunner::adaptive()->execute(
            getopt('', ['namespace::', 'name::', 'tag::', 'debug', 'detach', 'shmkey::'])
        ));
    }

    /**
     * Locates the application class the project's bootstrap file declared, so a
     * generic entry point (the thread runner) can reach {@see executor()} without
     * knowing the project's naming.
     *
     * Call it only after the bootstrap file has been required: it looks at what is
     * actually declared, and a project declares exactly one application class.
     *
     * @return class-string<WinterApplication>
     */
    public static function discoverAppClass(): string
    {
        $found = array_values(array_filter(
            get_declared_classes(),
            static fn(string $class): bool => is_subclass_of($class, self::class),
        ));

        return match (count($found)) {
            1       => $found[0],
            0       => throw new ApplicationConfigException(
                'No application class found. The bootstrap file must declare a class '
                . 'extending ' . self::class . '.'
            ),
            default => throw new ApplicationConfigException(
                'Several application classes are declared (' . implode(', ', $found)
                . '); the bootstrap file must declare exactly one.'
            ),
        };
    }

    /**
     * Boots the application once, then dispatches:
     *   - `call run` / `call run dev` → bring the app up ({@see serve()});
     *   - bare `call` or any other verb → the console dispatcher (bare → help).
     *
     * @param array $argv Raw $argv (script name in [0]).
     */
    final public static function run(array $argv = []): never
    {
        self::$bootStartedAt = hrtime(true);
        $args = ApplicationArguments::parse($argv);

        try {
            static::bootstrap($args);

            if (self::startsTheApplication($args)) {
                // `run dev` / `application start dev`: the word after the verb.
                $mode = $args->command() === 'run' ? $args->sub() : $args->positional(2);
                $watch = $mode === 'dev' || $args->flag('w') || $args->has('watcher');
                static::serve($watch, $args);
            }
        } catch (\Throwable $e) {
            static::bootFailed($e);
        }

        // Bare `call` and every other verb are console commands (bare → Help); they
        // run after the same boot.
        LoggerFactory::setDefaultChannel('sys');
        new Core($args->raw())->run();
        exit(0);
    }

    /**
     * Whether the command line starts the application in this process: `call run`, or its
     * full name `call application start` (`call app start`).
     */
    private static function startsTheApplication(ApplicationArguments $args): bool
    {
        return $args->command() === 'run'
            || (in_array($args->command(), ['application', 'app'], true) && $args->sub() === 'start');
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    /**
     * Reports a start that did not happen, then exits with 1.
     *
     * With `DEBUG=false` the kernel turns PHP's own error output off, so an exception
     * escaping the boot used to end the process with code 255 and not a single line
     * anywhere: a container restarting in a loop with an empty `docker logs`. The
     * checks that run at boot — undeclared caches, an `#[Async]` method that cannot be
     * proxied, an empty manifest — exist precisely to fail loudly before traffic
     * arrives, and they failed silently in the one place that matters.
     *
     * So one line always goes to stderr, whatever `DEBUG` and the log settings say:
     * the logger is off unless `LOG_LEVEL` is set, and the failure may come before it
     * is configured at all. The line carries the exception's own message — a statement
     * about the application's configuration, not about any request — and the stack
     * trace only with `DEBUG=true`. The same failure is logged as critical when a
     * logger is available.
     */
    private static function bootFailed(\Throwable $e): never
    {
        self::reportFailure('boot failed', $e);

        exit(1);
    }

    /**
     * One line that must be seen, whatever `DEBUG` and the log settings are: to stderr
     * (`winter: <message>`) and as critical to the log when a logger is available.
     */
    private static function reportLine(string $message): void
    {
        $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        if ($stderr !== false) {
            fwrite($stderr, "winter: {$message}\n");
        }
        try {
            LoggerFactory::getLogger(static::class)->critical($message);
        } catch (\Throwable) {
            // The stderr line above already said it.
        }
    }

    /**
     * Says why a start failed, whatever `DEBUG` and the log settings are: one line to
     * stderr (`winter: <what> — <exception>: <message>`, the trace only with
     * `DEBUG=true`) and the same as critical to the log when a logger is available.
     * See {@see bootFailed()} for why both.
     */
    private static function reportFailure(string $what, \Throwable $e): void
    {
        $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        if ($stderr !== false) {
            fwrite($stderr, sprintf(
                "winter: %s — %s: %s\n%s",
                $what,
                $e::class,
                $e->getMessage(),
                env('DEBUG', false) ? $e->getTraceAsString() . "\n" : '',
            ));
        }

        // An exception built by a static factory (`CacheException::of()`) reports the
        // factory as its origin; the frame that called it is the one worth reading.
        $origin = $e->getFile() . ':' . $e->getLine();
        $frame = $e->getTrace()[0] ?? [];
        if (isset($frame['class'], $frame['file']) && is_a($frame['class'], \Throwable::class, true)) {
            $origin = $frame['file'] . ':' . ($frame['line'] ?? 0);
        }

        try {
            LoggerFactory::getLogger(static::class)->critical(ucfirst($what) . ': ' . $e->getMessage(), [
                'exception' => $e::class,
                'file'      => $origin,
            ]);
        } catch (\Throwable) {
            // The logger may not exist yet; the stderr line above already said it.
        }
    }

    /**
     * The boot sequence: kernel init → one project scan (DI + beans + configurers)
     * → apply discovered configuration (logging, CORS, imports). Every entry runs
     * this first.
     */
    protected static function bootstrap(ApplicationArguments $args): void
    {
        self::$appClass = static::class;
        static::configure($args);

        $c = Container::init();
        self::$container = $c;
        $debug = (bool) env('DEBUG', false);

        static::assertManifestIsNotInherited();

        // Imports come first: the boot scan has to cover the packages, and until this
        // has run the registry is empty. It used to sit at the end of this method, which
        // is why a package's #[Bean], #[Async] and HealthContributor were invisible while
        // its routes and commands worked — one #[Import] meaning two different things.
        $imports = static::applyImports();
        /** @var array<string, ContributionCollector> $contributed */
        $contributed = [];

        $config = new ConfigurationCollector($c);
        $webCollector = new ImplementorCollector(WebConfigurer::class);
        $logCollector = new ImplementorCollector(LoggingConfigurer::class);
        $actuatorCollector = new ImplementorCollector(HealthContributor::class);
        $serverCollector = new ImplementorCollector(ServerConfigurer::class);
        $scopeGraph = new ScopeGraphCollector();

        // Proxying is opt-in per feature, like Spring's @EnableAsync / @EnableCaching:
        // without the attribute, methods carrying #[Async] run synchronously and the
        // cache attributes do nothing. A class gets one proxy whatever features it
        // uses, so one collector serves them all. It must run after DICollector
        // (which rebinds a class to itself), so it is collected last.
        $caching = static::hasAttribute(EnableCaching::class);
        $proxies = static::proxyCollector($c, $debug);
        $cacheCollector = $caching ? new ImplementorCollector(CacheConfigurer::class) : null;

        // Everything an imported package may contribute. The application adds
        // ServerConfigurer to this on its own pass; a package may not.
        $shared = static function (Scanner $scanner) use (
            $c,
            $config,
            $webCollector,
            $logCollector,
            $actuatorCollector,
            $scopeGraph,
            $cacheCollector,
            $proxies,
        ): Scanner {
            $scanner
                ->collect(new DICollector($c))
                ->collect($config)
                ->collect($webCollector)
                ->collect($logCollector)
                ->collect($actuatorCollector)
                ->collect($scopeGraph);

            if ($cacheCollector !== null) {
                $scanner->collect($cacheCollector);
            }
            if ($proxies !== null) {
                $scanner->collect($proxies);
            }

            return $scanner;
        };

        // Packages first, the application last: contributions that overwrite rather than
        // add up must end with the application's value, whatever the filesystem order.
        foreach (Plugin::all() as $plugin) {
            $intruders     = new ImplementorCollector(ServerConfigurer::class);
            $contributions = new ContributionCollector();
            foreach ($plugin->roots as $root) {
                $shared(ClassScanner::scanner($root))
                    ->collect($intruders)
                    ->collect($contributions)
                    ->execute();
            }
            static::refuseServerConfigurer($plugin, $intruders->getResult());
            $contributed[$plugin->package] = $contributions;
        }

        $shared(ClassScanner::scanner(
            rootDir: Kernel::$pathRoot,
            cache: $debug ? null : Kernel::$pathStorageVolatile . '/di.php',
        ))->collect($serverCollector)->execute();

        // Before anything is resolved: a #[Singleton] holding a #[Request] bean would
        // freeze the first request's instance for the worker's lifetime, and say nothing.
        $scopeGraph->assertNoFrozenRequestScope();

        $proxies?->flush();

        // Default contextual logger: an injected LoggerInterface is named after the
        // class it is injected into.
        $c->contextual(
            LoggerInterface::class,
            static fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app'),
        );

        static::applyLogging($c, $logCollector->getResult());
        static::reportImports($imports, $contributed);
        static::applyCors($c, $webCollector->getResult());
        static::applyActuator($actuatorCollector->getResult());
        static::applyServerConfigurer($serverCollector->getResult());

        if ($cacheCollector !== null) {
            static::applyCaching($c, $cacheCollector->getResult(), $proxies?->proxied() ?? []);
        }
    }

    /**
     * The one collector that swaps services for their generated proxies, carrying an
     * interceptor per enabled feature; null when no feature asks for proxies.
     */
    private static function proxyCollector(Container $c, bool $debug): ?ProxyCollector
    {
        $interceptors = [];
        if (static::hasAttribute(EnableAsync::class)) {
            $interceptors[] = new AsyncInterceptor();
        }
        if (static::hasAttribute(EnableCaching::class)) {
            $interceptors[] = new CacheInterceptor();
        }

        if ($interceptors === []) {
            return null;
        }

        return new ProxyCollector(
            $c,
            ProxyFactory::forKernel($interceptors, $debug),
            $debug ? null : Kernel::$pathStorageVolatile . '/proxy.php',
        );
    }

    /**
     * Declares the caches and checks every cache attribute against them.
     *
     * The check runs here, at boot, rather than at the first call: a misspelt cache
     * name would otherwise fail one request in production — or, on an eviction, fail
     * nothing at all and keep serving stale data. It runs with `CACHE_ENABLED=false`
     * too, so switching the cache back on cannot uncover a typo.
     *
     * Tables are not created here — {@see serve()} does that in the master, right
     * before the fork — so a console command does not reserve their memory.
     *
     * @param list<\ReflectionClass> $configurers
     * @param array<class-string, class-string> $proxied Classes swapped for proxies.
     * @throws CacheException
     */
    private static function applyCaching(Container $c, array $configurers, array $proxied): void
    {
        $definitions = Caches::declare($c, $configurers, array_keys($proxied));

        $enabled = (bool) env('CACHE_ENABLED', true);
        CacheManager::configure($definitions, $enabled);

        if (!$enabled) {
            LoggerFactory::getLogger(static::class)->warning(
                'Caching is disabled by CACHE_ENABLED=false: every cached method runs its body. '
                . 'Remove the variable and restart to turn it back on.'
            );
        }
    }

    /**
     * Refuses a manifest attribute left on an ancestor of the application class.
     *
     * PHP does not inherit attributes — a `#[EnableHttp]` on an abstract base reads as
     * zero attributes on the class that extends it. The manifest is deliberately not
     * made inheritable: its value is that one class tells you everything the process
     * will start, and walking a hierarchy to find a `#[EnableProcess]` two levels up
     * would spend exactly that.
     *
     * What has to go is the silence. A missing manifest is caught only by `serve`, and
     * a partial loss — the base carrying `#[EnableActuator]` while the child carries
     * `#[EnableHttp]` — is caught nowhere: the application starts, the actuator does not,
     * and nothing says why.
     *
     * @throws ApplicationConfigException
     */
    private static function assertManifestIsNotInherited(): void
    {
        $manifest = [
            EnableHttp::class,
            EnableWeb::class,
            EnableScheduler::class,
            EnableProcess::class,
            EnableDaemon::class,
            EnableActuator::class,
            EnableAsync::class,
            EnableCaching::class,
            Import::class,
        ];

        for (
            $ref = new \ReflectionClass(static::class)->getParentClass();
            $ref !== false;
            $ref = $ref->getParentClass()
        ) {
            foreach ($manifest as $attribute) {
                if ($ref->getAttributes($attribute) === []) {
                    continue;
                }

                throw new ApplicationConfigException(sprintf(
                    '#[%s] is declared on %s, but PHP does not inherit attributes — '
                    . 'it has no effect on %s. Declare it on the application class itself.',
                    new \ReflectionClass($attribute)->getShortName(),
                    $ref->getName(),
                    static::class,
                ));
            }
        }
    }

    /**
     * A package may not decide where the server binds.
     *
     * Refused by name rather than ignored: overwriting the application's own tuning is
     * the kind of thing that shows up as "the port is wrong in production" and is traced
     * back through the scanner's walk order, if at all.
     *
     * @param list<\ReflectionClass> $found
     */
    private static function refuseServerConfigurer(PluginPackage $plugin, array $found): void
    {
        if ($found === []) {
            return;
        }

        throw new ApplicationConfigException(
            'Only the application may configure the server; found '
            . $found[0]->getName() . ' in ' . $plugin->package . '. '
            . 'A package may implement WebConfigurer (CORS) but not ServerConfigurer.'
        );
    }

    /**
     * @param list<\ReflectionClass> $configurers
     */
    private static function applyServerConfigurer(array $configurers): void
    {
        if (count($configurers) > 1) {
            throw new ApplicationConfigException(
                'More than one ServerConfigurer in the application: '
                . implode(', ', array_map(
                    static fn(\ReflectionClass $ref): string => $ref->getName(),
                    $configurers,
                ))
                . '. The server is one, so its configuration has one owner.'
            );
        }

        self::$serverConfigurer = $configurers === [] ? null : $configurers[0]->getName();
    }

    /**
     * @param list<\ReflectionClass> $configurers
     */
    private static function applyLogging(Container $c, array $configurers): void
    {
        if ($configurers === []) {
            return;
        }
        $registry = new ChannelRegistry();
        foreach ($configurers as $ref) {
            /** @var LoggingConfigurer $configurer */
            $configurer = $c->make($ref->getName());
            $configurer->configureChannels($registry);
        }
        $registry->apply();
    }

    /**
     * @param list<\ReflectionClass> $configurers
     */
    private static function applyCors(Container $c, array $configurers): void
    {
        $registry = new CorsRegistry();
        $classes = [];
        foreach ($configurers as $ref) {
            $classes[] = $ref->getName();
            /** @var WebConfigurer $configurer */
            $configurer = $c->make($ref->getName());
            $configurer->configureCors($registry);
        }
        self::$webConfigurers = $classes;

        if ($registry->isTouched()) {
            $registry->apply();
        }
    }

    /**
     * Resolves every #[Import] on the application class, in declaration order.
     *
     * The outcome of each is handed back rather than logged here: this runs before the
     * scan, and therefore before {@see applyLogging()} has had a chance to apply the
     * application's own channels and levels. Reporting from here would mean an
     * application that asked for `warning` and above still got these notices.
     *
     * @return list<array{package: string, prefix: string|null, imported: bool}>
     */
    private static function applyImports(): array
    {
        $outcomes = [];

        foreach (new \ReflectionClass(static::class)->getAttributes(Import::class) as $attribute) {
            /** @var Import $import */
            $import = $attribute->newInstance();
            $plugin = Plugin::registry($import->package, $import->prefix, $import->required);

            $outcomes[] = [
                'package'  => $import->package,
                'prefix'   => $plugin?->prefix,
                'imported' => $plugin !== null,
            ];
        }

        return $outcomes;
    }

    /**
     * Says which packages came in and which did not.
     *
     * A missing optional package is the interesting half: `required: false` is a
     * deliberate "carry on without it", and until now carrying on looked exactly like
     * having it — the same silent start, minus a feature nobody mentioned.
     *
     * @param list<array{package: string, prefix: string|null, imported: bool}> $outcomes
     * @param array<string, ContributionCollector> $contributed What each package brought.
     */
    private static function reportImports(array $outcomes, array $contributed = []): void
    {
        if ($outcomes === []) {
            return;
        }

        $logger = LoggerFactory::getLogger(static::class);

        foreach ($outcomes as $outcome) {
            if (!$outcome['imported']) {
                $logger->warning(sprintf(
                    'Optional package %s is not installed — import skipped.',
                    $outcome['package'],
                ), ['package' => $outcome['package']]);
                continue;
            }

            $logger->notice(
                $outcome['prefix'] === null
                    ? sprintf('Package %s imported — no routes mounted.', $outcome['package'])
                    : sprintf(
                        'Package %s imported — routes mounted under %s.',
                        $outcome['package'],
                        $outcome['prefix'],
                    ),
                ['package' => $outcome['package'], 'prefix' => $outcome['prefix']],
            );

            $contribution = $contributed[$outcome['package']] ?? null;
            if ($contribution !== null) {
                static::warnAboutIdleContributions($logger, $outcome, $contribution);
            }
        }
    }

    /**
     * Names a contribution that arrived but will never run.
     *
     * Each of these is a dead end that costs nothing at boot and shows up much later as
     * "the package does not work": routes that are collected and never served, tasks
     * collected and never triggered. The switch belongs to the application either way —
     * this only says which switch is missing, so the search does not start in the
     * package.
     *
     * @param array{package: string, prefix: string|null, imported: bool} $outcome
     */
    private static function warnAboutIdleContributions(
        LoggerInterface $logger,
        array $outcome,
        ContributionCollector $contribution,
    ): void {
        $package = $outcome['package'];

        if ($contribution->controllers() > 0) {
            if ($outcome['prefix'] === null) {
                $logger->warning(sprintf(
                    'Package %s brings %d controller(s) but was imported without a prefix, '
                    . 'so none of its routes are mounted. Pass a prefix to #[Import] to mount them.',
                    $package,
                    $contribution->controllers(),
                ), ['package' => $package, 'controllers' => $contribution->controllers()]);
            } elseif (!static::hasAttribute(EnableHttp::class) && !static::hasAttribute(EnableWeb::class)) {
                $logger->warning(sprintf(
                    'Package %s brings %d controller(s), but the application declares no '
                    . '#[EnableHttp], so nothing is served.',
                    $package,
                    $contribution->controllers(),
                ), ['package' => $package, 'controllers' => $contribution->controllers()]);
            }
        }

        if ($contribution->scheduledMethods() > 0 && !static::hasAttribute(EnableScheduler::class)) {
            $logger->warning(sprintf(
                'Package %s brings %d #[Scheduled] method(s), but the application declares no '
                . '#[EnableScheduler], so none of them run.',
                $package,
                $contribution->scheduledMethods(),
            ), ['package' => $package, 'scheduled' => $contribution->scheduledMethods()]);
        }
    }

    /**
     * Enables the actuator when the App class carries {@see EnableActuator}: registers
     * the discovered {@see HealthContributor} classes and hands the indicator +
     * optional guard middleware to {@see Health}, which {@see Router::fromScan()} then
     * wires into the `/actuator/*` routes. No attribute → actuator stays off.
     *
     * @param list<\ReflectionClass> $contributors
     */
    private static function applyActuator(array $contributors): void
    {
        $attributes = new \ReflectionClass(static::class)->getAttributes(EnableActuator::class);
        if ($attributes === []) {
            return;
        }

        /** @var EnableActuator $actuator */
        $actuator = $attributes[0]->newInstance();
        Health::setContributors(array_map(
            static fn(\ReflectionClass $ref): string => $ref->getName(),
            $contributors,
        ));
        Health::configure($actuator->indicator ?? HealthIndicator::class, $actuator->middleware);
    }

    // ── Serve ─────────────────────────────────────────────────────────────────

    /**
     * Brings the application up and blocks until it stops: this process — PID 1 in a
     * container — claims the application and supervises every component, the HTTP server
     * among them, each in a process of its own.
     *
     * Exits with 0 when stopped, 1 when a critical component could not run. Under
     * `call run dev` a change in a watched file restarts the whole application in place.
     *
     * @param bool $watch Attach the DevWatcher (memory + hot-reload) — development.
     */
    final public static function serve(bool $watch, ApplicationArguments $args): never
    {
        $components = static::resolveComponents();
        if ($components === []) {
            throw new ApplicationConfigException(
                'No components declared on ' . static::class . ': add at least one '
                . '#[EnableHttp], #[EnableWebSocket], #[EnableProcess], #[EnableDaemon] or #[EnableScheduler].'
            );
        }
        if (!function_exists('pcntl_fork')) {
            throw new ApplicationConfigException(
                '`call run` needs ext-pcntl to run its components (each runs in a process of its own).'
            );
        }

        $logger = LoggerFactory::getLogger(static::class);

        // Where each listening component binds: what it was declared with, then the
        // launch flags; for the HTTP server its configurer has the last word.
        $components = ListenFlags::apply($components, $args);
        $settings = null;
        $http = null;
        $companions = [];
        foreach ($components as $i => $component) {
            if ($component->kind === ComponentKind::Http) {
                $settings = static::buildServerSettings($component, $args, $logger);
                $http = $components[$i] = $component->at($settings->getHost(), $settings->getPort());
                continue;
            }
            $companions[] = $component;
        }
        self::assertFreePorts($companions, $http?->port);

        // Before anything binds or forks: a second run of the same application is refused
        // here, and every process forked from now on knows the run it belongs to.
        ApplicationRuntime::claim(static::class, $components);

        // Here, in the process every component is forked from: a table created after the
        // fork would exist in one process only.
        CacheManager::allocate();

        // What the HTTP server needs is prepared here, once — the route scan above all,
        // which every worker then shares from this process's memory. The server itself is
        // created in its own process: the one Swoole runs threads in must not be the one
        // that forks the rest.
        $web = $settings !== null ? static::prepareHttp($settings, $watch) : null;

        if (Banner::isEnabled($args)) {
            $rows = static::bannerRows($companions, $web?->host(), $web?->port(), $web?->scheme() ?? 'http');
            if ($web !== null) {
                // What the profile resolved to, because a limit nobody can see is a limit
                // nobody thinks of when a request starts queueing or a connection is refused.
                $rows[] = ['profile', self::profileSummary($web->settings)];
            }
            Banner::print($rows, self::elapsedMs());
        }

        $names = array_map(static fn(Component $c): string => $c->name(), $companions);
        $logger->info(sprintf(
            'Application up%s%s%s',
            $web !== null ? ': ' . BindAddress::url($web->scheme(), $web->host(), $web->port()) : ' (headless)',
            $names === [] ? '' : ($web !== null ? ' + ' : ': ') . '[' . implode(', ', $names) . ']',
            $watch ? ' (dev/watch)' : ''
        ));

        // Nothing the boot opened may be inherited by the components forked from here.
        BeforeFork::runAll();

        $supervisor = self::supervisor($components, $web);
        $code = $supervisor->run(untilDone: true);
        ApplicationRuntime::release(static::class);

        if ($supervisor->reloadRequested() && $web?->dev !== null) {
            $web->dev->reloadRequested();
            $web->dev->reexec();
        }

        exit($code);
    }

    /**
     * Prepares the HTTP server in the application's process: its settings, the route scan,
     * the dev watcher — what the server's own process then starts from.
     */
    private static function prepareHttp(ServerSettings $settings, bool $watch): HttpTier
    {
        if (!extension_loaded('swoole')) {
            throw new ApplicationConfigException(
                '`call run` with an HTTP server needs ext-swoole (pecl install swoole).'
            );
        }
        $settings->assertTls();

        // The same class-list cache the boot scan built a moment ago: without it this
        // walks the whole tree a second time, `require_once`-ing every file again — and
        // this process's memory is what every worker forks from.
        $router = Router::fromScan(
            Kernel::$pathRoot,
            cache: env('DEBUG', false) ? null : Kernel::$pathStorageVolatile . '/di.php',
        );

        // Says the arithmetic out loud before any worker exists: the memory limit is per
        // worker and shared by every coroutine in it, so what the box must hold is
        // worker_num × limit plus opcache. Warns, never refuses — over-committing is a
        // legitimate choice and this is a worst-case estimate.
        WorkerMemory::check(
            $settings->getMemoryLimit(),
            (int) ($settings->toArray()['worker_num'] ?? 1),
            LoggerFactory::getLogger('sys'),
        );

        // Built here so the server's process and this one name the same reload marker.
        $dev = $watch ? new DevWatcher([Kernel::$pathRoot]) : null;

        return new HttpTier($settings, $router, $dev);
    }

    /**
     * The HTTP server's own process: the Swoole server, from bind to shutdown. Ready for
     * the supervisor once the server has started — its port is bound.
     */
    private static function runHttp(HttpTier $web): void
    {
        // Every request coroutine needs the hooks, and the server's workers are forked from
        // this process: switched on here, for the whole server.
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Runtime::boot(RuntimeMode::Swoole);

        // Swoole binds IPv4 unless told otherwise, and refuses `::` with "Invalid IPv4
        // address"; an IPv6 socket on `::` takes IPv4 clients as well.
        // TLS is a flag on the socket: the certificate options alone leave it plain HTTP.
        $tls = $web->settings->isTls();
        $server = new \Swoole\Http\Server(
            $web->host(),
            $web->port(),
            SWOOLE_BASE,
            (BindAddress::isIpv6($web->host()) ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP) | ($tls ? SWOOLE_SSL : 0),
        );
        $server->set($web->settings->toArray());

        $router = $web->router;
        $trimThreshold = $web->settings->getMemoryTrimThreshold();
        $serverHeader = $web->settings->getServerHeader();
        $handler = static function (
            \Swoole\Http\Request $req,
            \Swoole\Http\Response $res
        ) use ($router, $trimThreshold, $serverHeader, $tls): void {
            // Before the route runs, so a response that sets its own `Server` replaces it;
            // an empty value makes Swoole send no header instead of its own name.
            $res->header('Server', $serverHeader);
            $request = new SwooleRequest($req, $tls);
            $isHead  = strtoupper($request->getMethod()) === 'HEAD';
            $router->handle($request, new SwooleResponse($res, $isHead));

            // After the response, not before it: giving memory back can take tens of
            // milliseconds when there is a lot of it, and the client should not wait for
            // that. A request that reserved nothing unusual pays 5 µs to find out.
            WorkerMemory::trimIfIdle($trimThreshold);
        };

        // Request workers log on 'http' with per-request coroutine isolation, and are
        // marked eligible to publish their connection-pool utilisation for
        // `call db pool` — the publisher itself only starts if a pool is ever opened.
        $memoryLimit    = $web->settings->getMemoryLimit();
        $requestTimeout = $web->settings->getRequestTimeout();
        $workerStart = static function (
            \Swoole\Http\Server $server,
            int $workerId
        ) use ($memoryLimit, $requestTimeout): void {
            cli_set_process_title("winter-http: Worker $workerId");
            // Per worker, because the limit is a property of this process — and a no-op
            // when nothing was configured, so PHP's own value stands.
            WorkerMemory::apply($memoryLimit);
            // One sweep per worker rather than a timer per request; a no-op at 0, so an
            // application that wants no deadline runs no timer at all.
            RequestWatchdog::enable($requestTimeout);
            LoggerFactory::setContextStorage(new CoroutineContext());
            LoggerFactory::setDefaultChannel('http');
            if (DepSupport::has(Dep::Ppa)) {
                PoolTelemetry::enable($workerId);
            }
        };

        // A worker cannot leave while its reactor still holds a repeating timer, so a
        // shutdown would hang until Swoole force-kills it ("worker exit timeout").
        // `workerExit` fires exactly while the reactor is trying to drain, which is
        // where those timers have to be released.
        $workerExit = static function (\Swoole\Http\Server $server, int $workerId): void {
            RequestWatchdog::disable();
            if (DepSupport::has(Dep::Ppa)) {
                PoolTelemetry::stop($workerId);
                PpaConnectionPool::shutdown();
            }
            if (DepSupport::has(Dep::Redis)) {
                RedisPool::shutdown();
            }
        };

        // Started means bound: from here the server accepts connections.
        $onStart = static function (): void {
            cli_set_process_title('winter-http: master');
            ComponentChannel::ready();
        };
        $server->on('managerStart', static fn() => cli_set_process_title('winter-http: manager'));

        if ($web->dev !== null) {
            $web->dev->attach($server, $workerStart, $onStart);
            $server->on('request', $web->dev->wrap($handler));
        } else {
            $server->on('start', $onStart);
            $server->on('workerStart', $workerStart);
            $server->on('request', $handler);
        }
        $server->on('workerExit', $workerExit);

        $server->start();

        // A watched file changed: the whole application restarts, so that every component
        // runs the new code — said to the supervisor, which owns the restart.
        if ($web->dev !== null && $web->dev->reloadPending()) {
            ComponentChannel::reload();
        }
    }

    /**
     * The supervisor of the background components, wired to this application: how to
     * start a component in its own process, and how to say that one could not run.
     *
     * @param list<Component> $components
     */
    private static function supervisor(array $components, ?HttpTier $web): Supervisor
    {
        $run = ApplicationRuntime::current()?->managedBy();
        return new Supervisor(
            $components,
            launch: static function (Component $component) use ($web): void {
                LoggerFactory::setContextStorage(new ProcessContext());
                LoggerFactory::setDefaultChannel('sys');
                ForkReset::runAll();
                if ($component->kind === ComponentKind::Http && $web !== null) {
                    self::runHttp($web);
                    return;
                }
                self::launch($component);
            },
            report: static fn(string $what, \Throwable $e) => self::reportFailure($what, $e),
            announce: static fn(string $message) => self::reportLine($message),
            // An outside program has no class, and so no record: it is only in the log.
            records: new class implements ComponentRecords {
                public function failed(Component $component): void
                {
                    $component->class !== null && $component->class::recordFailed();
                }

                public function stopped(Component $component): void
                {
                    $component->class !== null && $component->class::recordStopped();
                }

                public function forget(Component $component): void
                {
                    $component->class !== null && $component->class::forgetRecord();
                }

                public function outsider(Component $component): ?int
                {
                    return $component->class !== null ? $component->class::outsider() : null;
                }
            },
            name: static fn(Component $component): string => $component->name(),
            // The application's own voice, as its "Application up" line: PID 1 is the
            // application, and what it says about its components is the application talking.
            logger: LoggerFactory::getLogger(static::class),
            commands: $run === null ? null : static fn(): array => ComponentControl::pending($run),
            publish: $run === null
                ? null
                : static fn(array $units) => ApplicationRuntime::publishUnits($run->appClass, $units),
        );
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Builds the server settings: bind address + Swoole options. The address arrives
     * resolved — `#[EnableHttp]`, then `--http.port` / `--http.host`; base Swoole options
     * come from .env; the application's {@see ServerConfigurer} may then change both,
     * and has the last word.
     *
     * Code that overrides a launch flag does so on purpose, but the operator who typed
     * the flag is told: the server would otherwise listen somewhere they did not ask for,
     * and say nothing.
     */
    private static function buildServerSettings(
        Component $http,
        ApplicationArguments $args,
        ?LoggerInterface $logger = null,
    ): ServerSettings {
        $settings = ServerSettings::fromEnv($http->host, $http->port);
        $c = self::$container;
        if ($c !== null && self::$serverConfigurer !== null) {
            /** @var ServerConfigurer $configurer */
            $configurer = $c->make(self::$serverConfigurer);
            $configurer->configureServer($settings, $args);
            foreach (['port' => $settings->getPort(), 'host' => $settings->getHost()] as $field => $value) {
                $asked = $http->{$field};
                if ($value !== $asked && ListenFlags::given($args, $http, $field)) {
                    $logger?->warning(sprintf(
                        '--http.%s=%s is overridden by %s: binding to %s.',
                        $field,
                        $asked,
                        self::$serverConfigurer,
                        $value,
                    ));
                }
            }
        }
        return $settings;
    }

    /**
     * Builds the component manifest from the App class's #[Enable*] attributes.
     * Each attribute maps to one {@see Component}; {@see EnableAsync} and
     * {@see EnableCaching} are not here — they are boot toggles read in
     * {@see bootstrap()}, not components.
     *
     * @return list<Component>
     */
    private static function resolveComponents(): array
    {
        $ref = new \ReflectionClass(static::class);
        $components = [];

        $http = $ref->getAttributes(EnableHttp::class);
        if ($http !== [] && $ref->getAttributes(EnableWeb::class) !== []) {
            throw new ApplicationConfigException(
                '#[EnableWeb] and #[EnableHttp] are the same server: declare #[EnableHttp] only.'
            );
        }
        if ($http !== []) {
            $h = $http[0]->newInstance();
            $components[] = self::component(
                '#[EnableHttp]',
                static fn() => Component::http(
                    host: $h->host,
                    port: $h->port,
                    critical: $h->critical,
                    retries: $h->retries,
                    startTimeout: $h->startTimeout,
                ),
            );
        } elseif ($ref->getAttributes(EnableWeb::class) !== []) {
            $components[] = Component::http();
        }
        $scheduler = $ref->getAttributes(EnableScheduler::class);
        if ($scheduler !== []) {
            $s = $scheduler[0]->newInstance();
            $components[] = self::component(
                '#[EnableScheduler]',
                static fn() => Component::scheduler($s->class, $s->critical, $s->retries, $s->startTimeout),
            );
        }
        foreach ($ref->getAttributes(EnableProcess::class) as $attribute) {
            $process = $attribute->newInstance();
            $class = $process->class;
            if (is_subclass_of($class, WebSocket::class)) {
                throw new ApplicationConfigException(sprintf(
                    '%s is a WebSocket and needs a port: use #[EnableWebSocket(%s::class, port: …)] '
                    . 'instead of #[EnableProcess].',
                    $class,
                    self::shortName($class),
                ));
            }
            $components[] = self::component(
                '#[EnableProcess(' . self::bareName($class) . ')]',
                static fn() => Component::process(
                    $class,
                    $process->critical,
                    $process->retries,
                    $process->startTimeout,
                ),
            );
        }
        foreach ($ref->getAttributes(EnableWebSocket::class) as $attribute) {
            $socket = $attribute->newInstance();
            if (!is_subclass_of($socket->class, WebSocket::class)) {
                throw new ApplicationConfigException(
                    "#[EnableWebSocket] expects a WebSocket class, got {$socket->class}."
                );
            }
            $components[] = self::component(
                '#[EnableWebSocket(' . self::bareName($socket->class) . ')]',
                static fn() => Component::websocket(
                    $socket->class,
                    $socket->port,
                    $socket->host,
                    $socket->critical,
                    $socket->retries,
                    $socket->startTimeout,
                ),
            );
        }
        foreach ($ref->getAttributes(EnableDaemon::class) as $attribute) {
            $daemon = $attribute->newInstance();
            $components[] = self::component(
                '#[EnableDaemon(' . self::bareName($daemon->class) . ')]',
                static fn() => Component::daemon($daemon->class, $daemon->critical, $daemon->startTimeout),
            );
        }

        $components = [...$components, ...self::declaredInCode()];
        self::assertDistinct($components);

        return $components;
    }

    /**
     * What the application runs, and where each component was declared — the manifest
     * `call application info` shows, read without starting anything.
     *
     * @return list<array{component: Component, source: string}>
     * @throws ApplicationConfigException The manifest is invalid, as it would be at startup.
     */
    public static function manifest(): array
    {
        $all = static::resolveComponents();
        $inCode = count(self::declaredInCode());
        $fromAttributes = count($all) - $inCode;
        $web = static::hasAttribute(EnableWeb::class) ? '#[EnableWeb]' : '#[EnableHttp]';

        $manifest = [];
        foreach ($all as $i => $component) {
            $manifest[] = [
                'component' => $component,
                'source' => $i >= $fromAttributes ? 'components()' : match ($component->kind) {
                    ComponentKind::Http => $web,
                    ComponentKind::Process => '#[EnableProcess]',
                    ComponentKind::Daemon => '#[EnableDaemon]',
                    ComponentKind::Scheduler => '#[EnableScheduler]',
                    ComponentKind::WebSocket => '#[EnableWebSocket]',
                    ComponentKind::Command => 'components()',
                },
            ];
        }
        return $manifest;
    }

    /**
     * The HTTP server's settings as `call run` would resolve them without flags — from
     * `#[EnableHttp]`, the environment and the application's ServerConfigurer. Null when
     * the application has no HTTP server.
     */
    public static function httpSettings(): ?ServerSettings
    {
        foreach (static::resolveComponents() as $component) {
            if ($component->kind === ComponentKind::Http) {
                return static::buildServerSettings($component, ApplicationArguments::parse([]));
            }
        }
        return null;
    }

    /**
     * What the {@see components()} hook declares.
     *
     * @return list<Component>
     */
    private static function declaredInCode(): array
    {
        $declared = new Components(Kernel::$pathRoot ?? '');
        try {
            static::components($declared);
        } catch (\InvalidArgumentException $e) {
            throw new ApplicationConfigException(static::class . '::components(): ' . $e->getMessage(), previous: $e);
        }
        return $declared->all();
    }

    /**
     * Declares components in code — what the #[Enable*] attributes cannot: conditions,
     * values from the environment, lists from configuration, outside programs. Runs at
     * boot, after the scan: the container is ready. What it declares adds to the
     * attributes.
     *
     * ```
     * protected static function components(Components $components): void
     * {
     *     if (env('ROLE') !== 'web') {
     *         $components->scheduler();
     *     }
     *     $components->websocket(Chat::class, port: (int) env('CHAT_WS_PORT', 8001));
     *     $components->script('scripts/legacy-sync.php', critical: false);
     * }
     * ```
     *
     * @link https://winterframe.net/docs/components#components Declaring components in code
     */
    protected static function components(Components $components): void
    {
    }

    /**
     * One class runs once — a process is a singleton per class — and an outside program
     * is told apart by its name: either declared twice stops the boot.
     *
     * @param list<Component> $components
     */
    private static function assertDistinct(array $components): void
    {
        $seen = [];
        foreach ($components as $component) {
            if ($component->kind === ComponentKind::Http) {
                continue;
            }
            $key = $component->class ?? 'command:' . $component->name();
            if (isset($seen[$key])) {
                throw new ApplicationConfigException(sprintf(
                    '%s is declared twice — %s runs once per application.',
                    $component->class !== null ? $component->name() : "The program \"{$component->name()}\"",
                    $component->class !== null ? 'a component' : 'each program name',
                ));
            }
            $seen[$key] = true;
        }
    }

    /**
     * Builds a component from its declaration, naming the declaration when its values are
     * out of range — a negative `retries` is a boot error, not a mystery.
     *
     * @param \Closure(): Component $build
     */
    private static function component(string $declaration, \Closure $build): Component
    {
        try {
            return $build();
        } catch (\InvalidArgumentException $e) {
            throw new ApplicationConfigException("{$declaration}: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * True if the App class carries the given attribute.
     *
     * @param class-string $attribute
     */
    private static function hasAttribute(string $attribute): bool
    {
        return new \ReflectionClass(static::class)->getAttributes($attribute) !== [];
    }

    /**
     * Builds the startup-banner rows from the live manifest: the web endpoint (when
     * hosting one), each companion, and the async toggle. Only what is actually
     * running appears.
     *
     * @param list<Component> $companions
     * @return list<array{string, string}>
     */
    /**
     * The profile and the numbers it resolved to, for the startup banner.
     *
     * `Profile::Stress` prints a warning instead of numbers: it removes the caps and the
     * periodic work that would distort a measurement, which is right for a benchmark and
     * wrong for anything else, and a banner is the last place it can still be noticed.
     */
    /**
     * The resolved server profile in one line, for the banner and `call application info`.
     *
     * @internal
     */
    public static function profileSummary(ServerSettings $settings): string
    {
        $profile = $settings->getProfile();
        if (!$profile->guards()) {
            return $profile->value . ' — guards off, benchmarks only';
        }

        return sprintf(
            '%s · %d in flight · %d connections · recycle at %s',
            $profile->value,
            $settings->getMaxConcurrency(),
            $settings->getMaxConnections(),
            number_format($settings->getMaxRequest()),
        );
    }

    private static function bannerRows(array $companions, ?string $host, ?int $port, string $scheme = 'http'): array
    {
        $rows = [];
        if ($host !== null) {
            $rows[] = ['web', BindAddress::url($scheme, $host, (int) $port)];
        }
        foreach ($companions as $companion) {
            $rows[] = match ($companion->kind) {
                ComponentKind::Scheduler => ['scheduler', 'enabled'],
                ComponentKind::WebSocket => ['websocket', sprintf(
                    '%s %s',
                    $companion->name(),
                    BindAddress::url('ws', $companion->host, $companion->port),
                )],
                ComponentKind::Command => ['command', $companion->name()],
                default => [self::componentLabel($companion->kind), $companion->name()],
            };
        }
        if (static::hasAttribute(EnableAsync::class)) {
            $rows[] = ['async', 'enabled'];
        }
        if (static::hasAttribute(EnableCaching::class)) {
            $rows[] = ['cache', self::cacheSummary()];
        }

        return $rows;
    }

    /**
     * The declared caches and the shared memory their tables took, for the banner —
     * the one place a table's up-front reservation is visible before it matters.
     */
    private static function cacheSummary(): string
    {
        if (!CacheManager::isEnabled()) {
            return 'disabled by CACHE_ENABLED';
        }

        $counts = [];
        $bytes = 0;
        foreach (CacheManager::definitions() as $definition) {
            $bytes += $definition->reservedBytes();
            $counts[$definition->type->value] = ($counts[$definition->type->value] ?? 0) + 1;
        }

        $parts = [];
        foreach (CacheType::cases() as $type) {
            if (isset($counts[$type->value])) {
                $parts[] = $counts[$type->value] . ' ' . $type->value;
            }
        }
        if ($bytes > 0) {
            $parts[] = sprintf('≈%d MB shared', (int) ceil($bytes / 1048576));
        }

        return $parts === [] ? 'no caches declared' : implode(' · ', $parts);
    }

    /**
     * Starts a background component in the current (child) process. A WebSocket gets
     * the port and host its #[EnableWebSocket] declared; everything else starts bare.
     */
    private static function launch(Component $component): void
    {
        $class = (string) $component->class;
        ApplicationRuntime::markManaged($class);
        if ($component->kind === ComponentKind::WebSocket) {
            $class::start($component->port, $component->host);
            return;
        }
        // Taken in on request: started with what it was started with.
        $class::startWith($component->args);
    }

    /**
     * Refuses two components on one port before anything binds: one of them would die
     * on "address in use" after the server is up, and be restarted forever.
     *
     * @param list<Component> $companions
     * @param int|null $httpPort The web tier's port, when there is one.
     */
    private static function assertFreePorts(array $companions, ?int $httpPort): void
    {
        $taken = $httpPort === null ? [] : [$httpPort => 'the web server'];
        foreach ($companions as $companion) {
            if ($companion->kind !== ComponentKind::WebSocket) {
                continue;
            }
            $name = self::shortName((string) $companion->class);
            if (isset($taken[$companion->port])) {
                throw new ApplicationConfigException(sprintf(
                    'Port %d is used twice: by %s and by %s. One port serves one process — '
                    . 'give each its own and route by path in the proxy.',
                    $companion->port,
                    $taken[$companion->port],
                    $name,
                ));
            }
            $taken[$companion->port] = $name;
        }
    }

    private static function componentLabel(ComponentKind $kind): string
    {
        return match ($kind) {
            ComponentKind::Daemon => 'daemon',
            default               => 'process',
        };
    }

    /** Milliseconds from boot start to now (0.0 before {@see run()} sets the mark). */
    private static function elapsedMs(): float
    {
        return self::$bootStartedAt > 0
            ? (hrtime(true) - self::$bootStartedAt) / 1e6
            : 0.0;
    }

    protected static function rootPath(): string
    {
        return dirname((string) new \ReflectionClass(static::class)->getFileName());
    }

    private static function shortName(string $class): string
    {
        return new \ReflectionClass($class)->getShortName();
    }

    /** The last segment of a class name — without loading the class, which may not exist yet. */
    private static function bareName(string $class): string
    {
        $at = strrpos($class, '\\');
        return $at === false ? $class : substr($class, $at + 1);
    }
}
