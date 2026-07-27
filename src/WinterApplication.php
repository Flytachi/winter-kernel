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
use Flytachi\Winter\K2\App\Attribute\Import;
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
 * Extend it once, declare what the application contains via {@see components()},
 * then route the CLI through {@see main()} from a single file:
 * ```
 * #[Import('acme/auth-plugin', '/auth')]
 * final class App extends WinterApplication
 * {
 *     protected static function configure(ApplicationArguments $args): void
 *     {
 *         Kernel::init(pathRoot: __DIR__);
 *     }
 *
 *     protected static function components(): array
 *     {
 *         return [
 *             Component::http(port: 8000),   // web server (optional)
 *             Component::daemon(Emails::class),
 *             Component::scheduler(),
 *         ];
 *     }
 * }
 *
 * // call — the one entry:
 * App::main($argv);
 * ```
 *
 * Configuration is not a set of hook methods on this class; it lives in ordinary
 * classes the scanner finds:
 *   - {@see App\Attribute\Configuration}/{@see App\Attribute\Bean} — DI factories;
 *   - {@see WebConfigurer} — CORS + Swoole server tuning;
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
    /** @var list<class-string<WebConfigurer>> */
    private static array $webConfigurers = [];

    /** Returns the concrete application class name set during boot. */
    public static function getAppClass(): string
    {
        return self::$appClass;
    }

    // ── Hooks (override in your App class) ────────────────────────────────────

    /**
     * Declares the long-lived components this application is made of.
     *
     * Build each entry with a {@see Component} factory — never the constructor.
     *
     * @return list<Component>
     */
    abstract protected static function components(): array;

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
    public static function main(array $argv = []): never
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

        $async = new AsyncCollector(
            $c,
            ProxyFactory::forKernel($debug),
            $debug ? null : Kernel::$pathStorageVolatile . '/async.php',
        );
        $config = new ConfigurationCollector($c);
        $webCollector = new ImplementorCollector(WebConfigurer::class);
        $logCollector = new ImplementorCollector(LoggingConfigurer::class);

        Scanner::run(
            rootDir: Kernel::$pathRoot,
            cache: $debug ? null : Kernel::$pathStorageVolatile . '/di.php',
        )
            ->collect(new DICollector($c))
            ->collect($config)
            ->collect($async)
            ->collect($webCollector)
            ->collect($logCollector)
            ->execute();

        $async->flush();

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
        /** @var ?Component $http */
        $http = null;
        /** @var list<Component> $sockets */
        $sockets = [];
        /** @var list<Component> $companions */
        $companions = [];

        foreach (static::components() as $component) {
            if (!$component instanceof Component) {
                throw new ApplicationConfigException(
                    'components() must return ' . Component::class
                    . ' instances; got ' . get_debug_type($component) . '.'
                );
            }
            match ($component->kind) {
                ComponentKind::Http      => $http = $component,
                ComponentKind::WebSocket => $sockets[] = $component,
                default                  => $companions[] = $component,
            };
        }

        if ($sockets !== []) {
            throw new ApplicationConfigException(
                'WebSocket components are not hosted by the bundled runtime yet — '
                . 'the port from the legacy engine is pending.'
            );
        }

        $logger = LoggerFactory::getLogger(static::class);

        if ($http !== null) {
            static::serveHttp($http, $companions, $watch, $args, $logger);
        }

        static::serveHeadless($companions, $logger);
    }

    /**
     * Web bundle: the Http component becomes the Swoole server; every other
     * component is attached with addProcess so the master supervises it.
     *
     * @param list<Component> $companions
     */
    private static function serveHttp(
        Component $http,
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

        $host = $args->option('host', $http->host) ?? $http->host;
        $port = $args->int('port', $http->port);

        $router = Router::fromScan(Kernel::$pathRoot);
        $router->static(Kernel::$pathPublic);

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Runtime::boot(RuntimeMode::Swoole);

        $server = new \Swoole\Http\Server($host, $port);
        $server->set(static::buildServerSettings());

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
    private static function serveHeadless(array $companions, LoggerInterface $logger): never
    {
        if ($companions === []) {
            throw new ApplicationConfigException(
                'Nothing to run: components() is empty. Declare at least one '
                . 'Component::http()/process()/daemon()/scheduler().'
            );
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
     * Base Swoole options from .env, tuned by every discovered {@see WebConfigurer}.
     *
     * @return array<string, mixed>
     */
    private static function buildServerSettings(): array
    {
        $settings = ServerSettings::fromEnv();
        $c = self::$container;
        if ($c !== null) {
            foreach (self::$webConfigurers as $class) {
                /** @var WebConfigurer $configurer */
                $configurer = $c->make($class);
                $configurer->configureServer($settings);
            }
        }
        return $settings->toArray();
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
