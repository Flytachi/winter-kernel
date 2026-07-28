<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Base\RuntimeMode;
use Flytachi\Winter\Console\Core;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\K2\App\ApplicationArguments;
use Flytachi\Winter\K2\App\ApplicationConfigException;
use Flytachi\Winter\K2\App\Attribute\EnableAsync;
use Flytachi\Winter\K2\App\Attribute\EnableDaemon;
use Flytachi\Winter\K2\App\Attribute\EnableProcess;
use Flytachi\Winter\K2\App\Attribute\EnableScheduler;
use Flytachi\Winter\K2\App\Attribute\EnableWeb;
use Flytachi\Winter\K2\App\Attribute\Import;
use Flytachi\Winter\K2\App\Banner;
use Flytachi\Winter\K2\App\Component;
use Flytachi\Winter\K2\App\ComponentKind;
use Flytachi\Winter\K2\App\Config\ChannelRegistry;
use Flytachi\Winter\K2\App\Config\CorsRegistry;
use Flytachi\Winter\K2\App\Config\LoggingConfigurer;
use Flytachi\Winter\K2\App\Config\ServerSettings;
use Flytachi\Winter\K2\App\Config\WebConfigurer;
use Flytachi\Winter\K2\Collector\ConfigurationCollector;
use Flytachi\Winter\K2\Collector\ImplementorCollector;
use Flytachi\Winter\K2\Concurrent\Async\AsyncCollector;
use Flytachi\Winter\K2\Concurrent\Async\Proxy\ProxyFactory;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\K2\Http\Adapter\SwooleRequest;
use Flytachi\Winter\K2\Http\Adapter\SwooleResponse;
use Flytachi\Winter\K2\Process\ForkReset;
use Flytachi\Winter\K2\Route\DevWatcher;
use Flytachi\Winter\K2\Route\Router;
use Flytachi\Winter\Logger\Context\CoroutineContext;
use Flytachi\Winter\Logger\Context\ProcessContext;
use Flytachi\Winter\Logger\LoggerFactory;
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

        $config = new ConfigurationCollector($c);
        $webCollector = new ImplementorCollector(WebConfigurer::class);
        $logCollector = new ImplementorCollector(LoggingConfigurer::class);

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

        $scan = Scanner::run(
            rootDir: Kernel::$pathRoot,
            cache: $debug ? null : Kernel::$pathStorageVolatile . '/di.php',
        )
            ->collect(new DICollector($c))
            ->collect($config)
            ->collect($webCollector)
            ->collect($logCollector);

        if ($async !== null) {
            $scan->collect($async);
        }
        $scan->execute();

        $async?->flush();

        // Default contextual logger: an injected LoggerInterface is named after the
        // class it is injected into.
        $c->contextual(
            LoggerInterface::class,
            static fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app'),
        );

        static::applyLogging($c, $logCollector->getResult());
        static::applyCors($c, $webCollector->getResult());
        static::applyImports();
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

    private static function applyImports(): void
    {
        foreach (new \ReflectionClass(static::class)->getAttributes(Import::class) as $attribute) {
            /** @var Import $import */
            $import = $attribute->newInstance();
            Plugin::registry($import->package, $import->prefix, $import->required);
        }
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

        $router = Router::fromScan(Kernel::$pathRoot);
        $router->static(Kernel::$pathPublic);

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Runtime::boot(RuntimeMode::Swoole);

        $server = new \Swoole\Http\Server($host, $port);
        $server->set($settings->toArray());

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

        $handler = static function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($router): void {
            $request = new SwooleRequest($req);
            $isHead  = strtoupper($request->getMethod()) === 'HEAD';
            $router->handle($request, new SwooleResponse($res, $isHead));
        };

        // Request workers log on 'http' with per-request coroutine isolation.
        $workerStart = static function (\Swoole\Http\Server $server, int $workerId): void {
            LoggerFactory::setContextStorage(new CoroutineContext());
            LoggerFactory::setDefaultChannel('http');
        };

        $dev = $watch ? new DevWatcher([Kernel::$pathRoot]) : null;
        if ($dev !== null) {
            $dev->attach($server, $workerStart);
            $server->on('request', $dev->wrap($handler));
        } else {
            $server->on('workerStart', $workerStart);
            $server->on('request', $handler);
        }

        if (Banner::isEnabled($args)) {
            Banner::print(static::bannerRows($companions, $host, $port), self::elapsedMs());
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
        if ($c !== null) {
            foreach (self::$webConfigurers as $class) {
                /** @var WebConfigurer $configurer */
                $configurer = $c->make($class);
                $configurer->configureServer($settings, $args);
            }
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
