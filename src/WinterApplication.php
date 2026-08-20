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
use Flytachi\Winter\Kernel\App\Attribute\EnableDaemon;
use Flytachi\Winter\Kernel\App\Attribute\EnableProcess;
use Flytachi\Winter\Kernel\App\Attribute\EnableScheduler;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\App\Attribute\Import;
use Flytachi\Winter\Kernel\App\Banner;
use Flytachi\Winter\Kernel\App\Component;
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
use Flytachi\Winter\Kernel\Concurrent\Async\AsyncCollector;
use Flytachi\Winter\Kernel\Concurrent\Async\Proxy\ProxyFactory;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleResponse;
use Flytachi\Winter\Kernel\Http\Health\Health;
use Flytachi\Winter\Kernel\Http\Health\HealthContributor;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;
use Flytachi\Winter\Kernel\Plugin;
use Flytachi\Winter\Kernel\Process\ForkReset;
use Flytachi\Winter\Kernel\Route\DevWatcher;
use Flytachi\Winter\Kernel\Route\RequestWatchdog;
use Flytachi\Winter\Kernel\Route\Router;
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
 * #[EnableWeb]
 * #[EnableAsync]
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
 * {@see Component} ({@see EnableWeb} → http, {@see EnableProcess}/{@see EnableDaemon}
 * → workers, {@see EnableScheduler} → scheduler), except {@see EnableAsync}, which
 * only toggles #[Async] proxying during boot.
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
        static::bootstrap(ApplicationArguments::parse($argv));

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
        static::bootstrap($args);

        if ($args->command() === 'run') {
            $watch = $args->sub() === 'dev' || $args->flag('w') || $args->has('watcher');
            static::serve($watch, $args);
        }

        // Bare `call` and every other verb are console commands (bare → Help); they
        // run after the same boot.
        LoggerFactory::setDefaultChannel('sys');
        new Core($args->raw())->run();
        exit(0);
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

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

        // #[Async] proxying is opt-in, like Spring's @EnableAsync: the collector is
        // created and wired only when #[EnableAsync] is present. Without it, classes
        // carrying #[Async] are not proxied and their methods run synchronously. It
        // must run after DICollector (which rebinds a class to itself), so it is
        // collected last.
        $async = static::hasAttribute(EnableAsync::class)
            ? new AsyncCollector(
                $c,
                ProxyFactory::forKernel($debug),
                $debug ? null : Kernel::$pathStorageVolatile . '/async.php',
            )
            : null;

        // Everything an imported package may contribute. The application adds
        // ServerConfigurer to this on its own pass; a package may not.
        $shared = static function (Scanner $scanner) use (
            $c,
            $config,
            $webCollector,
            $logCollector,
            $actuatorCollector,
            $scopeGraph,
            $async,
        ): Scanner {
            $scanner
                ->collect(new DICollector($c))
                ->collect($config)
                ->collect($webCollector)
                ->collect($logCollector)
                ->collect($actuatorCollector)
                ->collect($scopeGraph);

            if ($async !== null) {
                $scanner->collect($async);
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

        $async?->flush();

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
    }

    /**
     * Refuses a manifest attribute left on an ancestor of the application class.
     *
     * PHP does not inherit attributes — a `#[EnableWeb]` on an abstract base reads as
     * zero attributes on the class that extends it. The manifest is deliberately not
     * made inheritable: its value is that one class tells you everything the process
     * will start, and walking a hierarchy to find a `#[EnableProcess]` two levels up
     * would spend exactly that.
     *
     * What has to go is the silence. A missing manifest is caught only by `serve`, and
     * a partial loss — the base carrying `#[EnableActuator]` while the child carries
     * `#[EnableWeb]` — is caught nowhere: the application starts, the actuator does not,
     * and nothing says why.
     *
     * @throws ApplicationConfigException
     */
    private static function assertManifestIsNotInherited(): void
    {
        $manifest = [
            EnableWeb::class,
            EnableScheduler::class,
            EnableProcess::class,
            EnableDaemon::class,
            EnableActuator::class,
            EnableAsync::class,
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
            } elseif (!static::hasAttribute(EnableWeb::class)) {
                $logger->warning(sprintf(
                    'Package %s brings %d controller(s), but the application declares no '
                    . '#[EnableWeb], so nothing is served.',
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
     * Brings the application up and blocks until shutdown. With a
     * {@see Component::http()} declared: one Swoole HTTP server plus every other
     * component supervised via `addProcess`. Without it: headless.
     *
     * @param bool $watch Attach the DevWatcher (memory + hot-reload) — development.
     */
    final public static function serve(bool $watch, ApplicationArguments $args): never
    {
        $components = static::resolveComponents();
        if ($components === []) {
            throw new ApplicationConfigException(
                'No components declared on ' . static::class . ': add at least one '
                . '#[EnableWeb], #[EnableProcess], #[EnableDaemon] or #[EnableScheduler].'
            );
        }

        /** @var ?Component $http */
        $http = null;
        /** @var list<Component> $sockets */
        $sockets = [];
        /** @var list<Component> $companions */
        $companions = [];

        foreach ($components as $component) {
            match ($component->kind) {
                ComponentKind::Http      => $http = $component,
                ComponentKind::WebSocket => $sockets[] = $component,
                default                  => $companions[] = $component,
            };
        }

        if ($sockets !== []) {
            throw new ApplicationConfigException(
                'WebSocket components are not hosted by the bundled runtime yet.'
            );
        }

        $logger = LoggerFactory::getLogger(static::class);

        if ($http !== null) {
            static::serveHttp($companions, $watch, $args, $logger);
        }

        static::serveHeadless($companions, $args, $logger);
    }

    /**
     * Web bundle: the Http component becomes the Swoole server; every other
     * component is attached with addProcess so the master supervises it.
     *
     * @param list<Component> $companions
     */
    private static function serveHttp(
        array $companions,
        bool $watch,
        ApplicationArguments $args,
        LoggerInterface $logger,
    ): never {
        if (!extension_loaded('swoole')) {
            throw new ApplicationConfigException(
                '`call run` with a web tier needs ext-swoole (pecl install swoole).'
            );
        }

        $settings = static::buildServerSettings($args);
        $host = $settings->getHost();
        $port = $settings->getPort();

        // The same class-list cache the boot scan built a moment ago: without it this
        // walks the whole tree a second time, `require_once`-ing every file again — and
        // the master's memory is what every worker forks from.
        $router = Router::fromScan(
            Kernel::$pathRoot,
            cache: env('DEBUG', false) ? null : Kernel::$pathStorageVolatile . '/di.php',
        );

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Runtime::boot(RuntimeMode::Swoole);

        $server = new \Swoole\Http\Server($host, $port);
        $server->set($settings->toArray());

        // Says the arithmetic out loud before any worker exists: the memory limit is per
        // worker and shared by every coroutine in it, so what the box must hold is
        // worker_num × limit plus opcache. Warns, never refuses — over-committing is a
        // legitimate choice and this is a worst-case estimate.
        WorkerMemory::check(
            $settings->getMemoryLimit(),
            (int) ($settings->toArray()['worker_num'] ?? 1),
            LoggerFactory::getLogger('sys'),
        );

        $names = [];
        foreach ($companions as $companion) {
            $class = (string) $companion->class;
            $names[] = self::shortName($class);
            $server->addProcess(new \Swoole\Process(
                static function () use ($class): void {
                    // Reset runtime hooks (flags = 0) so the child is a clean plain
                    // process, exactly like a standalone launch.
                    \Swoole\Runtime::enableCoroutine(0);
                    LoggerFactory::setContextStorage(new ProcessContext());
                    LoggerFactory::setDefaultChannel('sys');
                    ForkReset::runAll();
                    $class::start();
                }
            ));
        }

        $trimThreshold = $settings->getMemoryTrimThreshold();
        $handler = static function (
            \Swoole\Http\Request $req,
            \Swoole\Http\Response $res
        ) use ($router, $trimThreshold): void {
            $request = new SwooleRequest($req);
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
        $memoryLimit    = $settings->getMemoryLimit();
        $requestTimeout = $settings->getRequestTimeout();
        $workerStart = static function (
            \Swoole\Http\Server $server,
            int $workerId
        ) use ($memoryLimit, $requestTimeout): void {
            cli_set_process_title("winter-web: Worker $workerId");
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

        $dev = $watch ? new DevWatcher([Kernel::$pathRoot]) : null;
        if ($dev !== null) {
            $dev->attach($server, $workerStart);
            $server->on('request', $dev->wrap($handler));
        } else {
            $server->on('workerStart', $workerStart);
            $server->on('request', $handler);
        }
        $server->on('workerExit', $workerExit);

        if (Banner::isEnabled($args)) {
            $rows = static::bannerRows($companions, $host, $port);
            // What the profile resolved to, because a limit nobody can see is a limit
            // nobody thinks of when a request starts queueing or a connection is refused.
            $rows[] = ['profile', self::profileSummary($settings)];
            Banner::print($rows, self::elapsedMs());
        }

        $logger->info(sprintf(
            'Application up: http://%s:%d%s%s',
            $host,
            $port,
            $names === [] ? '' : ' + [' . implode(', ', $names) . ']',
            $watch ? ' (dev/watch)' : ''
        ));

        $server->start();

        if ($dev !== null && $dev->reloadRequested()) {
            $dev->reexec();
        }

        exit(0);
    }

    /**
     * No web tier: run the background components directly — one in the foreground,
     * several under a small pcntl supervisor that forwards the stop signal.
     *
     * @param list<Component> $companions
     */
    private static function serveHeadless(
        array $companions,
        ApplicationArguments $args,
        LoggerInterface $logger,
    ): never {
        if ($companions === []) {
            throw new ApplicationConfigException(
                'Nothing to run: no components declared. Add at least one '
                . '#[EnableWeb]/#[EnableProcess]/#[EnableDaemon]/#[EnableScheduler].'
            );
        }

        if (Banner::isEnabled($args)) {
            Banner::print(static::bannerRows($companions, null, null), self::elapsedMs());
        }

        if (count($companions) === 1) {
            $class = (string) $companions[0]->class;
            $logger->info('Application up (headless): ' . self::shortName($class));
            $class::start();
            exit(0);
        }

        if (!function_exists('pcntl_fork')) {
            throw new ApplicationConfigException(
                'Running several headless components needs ext-pcntl.'
            );
        }

        $children = [];
        foreach ($companions as $companion) {
            $class = (string) $companion->class;
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException("WinterApplication: fork failed for {$class}.");
            }
            if ($pid === 0) {
                if (extension_loaded('swoole')) {
                    \Swoole\Runtime::enableCoroutine(0);
                }
                LoggerFactory::setContextStorage(new ProcessContext());
                LoggerFactory::setDefaultChannel('sys');
                ForkReset::runAll();
                $class::start();
                exit(0);
            }
            $children[$pid] = self::shortName($class);
        }

        $forward = static function (int $signo) use (&$children): void {
            foreach (array_keys($children) as $pid) {
                @posix_kill($pid, $signo);
            }
        };
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $forward);
        pcntl_signal(SIGINT, $forward);

        $logger->info('Application up (headless): [' . implode(', ', $children) . ']');

        while ($children !== []) {
            $pid = pcntl_waitpid(-1, $status);
            if ($pid > 0) {
                unset($children[$pid]);
            }
        }

        exit(0);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Builds the server settings: bind address + Swoole options. The default bind
     * policy is `--host`/`--port` (fallback `0.0.0.0:8000`); base Swoole options come
     * from .env; every discovered {@see WebConfigurer} may then tune both, re-deriving
     * host/port from any source (env, a custom flag, a literal) via the handle.
     */
    private static function buildServerSettings(ApplicationArguments $args): ServerSettings
    {
        $settings = ServerSettings::fromEnv(
            $args->option('host', '0.0.0.0') ?? '0.0.0.0',
            $args->int('port', 8000),
        );
        $c = self::$container;
        if ($c !== null && self::$serverConfigurer !== null) {
            /** @var ServerConfigurer $configurer */
            $configurer = $c->make(self::$serverConfigurer);
            $configurer->configureServer($settings, $args);
        }
        return $settings;
    }

    /**
     * Builds the component manifest from the App class's #[Enable*] attributes.
     * Each attribute maps to one {@see Component}; {@see EnableAsync} is not here —
     * it is a boot toggle read in {@see bootstrap()}, not a component.
     *
     * @return list<Component>
     */
    private static function resolveComponents(): array
    {
        $ref = new \ReflectionClass(static::class);
        $components = [];

        if ($ref->getAttributes(EnableWeb::class) !== []) {
            $components[] = Component::http();
        }
        $scheduler = $ref->getAttributes(EnableScheduler::class);
        if ($scheduler !== []) {
            $components[] = Component::scheduler($scheduler[0]->newInstance()->class);
        }
        foreach ($ref->getAttributes(EnableProcess::class) as $attribute) {
            $components[] = Component::process($attribute->newInstance()->class);
        }
        foreach ($ref->getAttributes(EnableDaemon::class) as $attribute) {
            $components[] = Component::daemon($attribute->newInstance()->class);
        }

        return $components;
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
    private static function profileSummary(ServerSettings $settings): string
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

    private static function bannerRows(array $companions, ?string $host, ?int $port): array
    {
        $rows = [];
        if ($host !== null) {
            $rows[] = ['web', sprintf('http://%s:%d', $host, $port)];
        }
        foreach ($companions as $companion) {
            $rows[] = $companion->kind === ComponentKind::Scheduler
                ? ['scheduler', 'enabled']
                : [self::componentLabel($companion->kind), self::shortName((string) $companion->class)];
        }
        if (static::hasAttribute(EnableAsync::class)) {
            $rows[] = ['async', 'enabled'];
        }

        return $rows;
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
}
