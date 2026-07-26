<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Base\RuntimeMode;
use Flytachi\Winter\K2\App\ApplicationConfigException;
use Flytachi\Winter\K2\App\Component;
use Flytachi\Winter\K2\App\ComponentKind;
use Flytachi\Winter\K2\Http\Adapter\SwooleRequest;
use Flytachi\Winter\K2\Http\Adapter\SwooleResponse;
use Flytachi\Winter\K2\Process\ForkReset;
use Flytachi\Winter\K2\Route\MemoryWatcher;
use Flytachi\Winter\K2\Route\Router;
use Flytachi\Winter\Logger\Context\CoroutineContext;
use Flytachi\Winter\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Single application entry point — the framework's answer to a Java `main()`.
 *
 * Extend it once, declare what the application contains via {@see components()},
 * then route the CLI through {@see run()} from a single file:
 * ```
 * final class App extends Application
 * {
 *     protected static function configure(): void
 *     {
 *         Kernel::init(pathRoot: __DIR__);
 *     }
 *
 *     protected static function components(): array
 *     {
 *         return [
 *             Component::http(port: 8000),   // web server (optional)
 *             Component::process(KernelSys::class),
 *             Component::daemon(Emails::class),
 *             Component::scheduler(),
 *         ];
 *     }
 * }
 *
 * // call  — the one entry:
 * App::run($argv);
 * ```
 *
 * {@see run()} is the CLI front door: it just dispatches the console (`run`,
 * `run dev`, `make`, `daemon`, `schedule`, …). The application itself is brought
 * up by `call run` / `call run dev`, which call {@see serve()}:
 *   - `call run`     → production: every component, MemoryWatcher OFF;
 *   - `call run dev` → development: every component, MemoryWatcher ON.
 *
 * Server mode is Swoole's strength — one process, many concerns, like a JVM. The
 * one {@see Component::http()} (if declared) becomes the HTTP server; the rest run
 * beside it. With no Http component the app runs headless (background components
 * only). FPM cannot host this bundle: under FPM the web tier is served by
 * {@see web()} per request and the other components run as standalone
 * `call daemon|process|schedule` processes.
 */
abstract class Application extends BaseBoot
{
    /**
     * Declares the long-lived components this application is made of.
     *
     * Override to list them; an empty list means the app is console-only. Build
     * each entry with a {@see Component} factory — never the constructor.
     *
     * @return list<Component>
     */
    protected static function components(): array
    {
        return [];
    }

    /**
     * The CLI front door (the app's `main()`). Boots once and dispatches the
     * console command in $argv, then exits. `call run` / `call run dev` reach
     * {@see serve()} from here.
     *
     * @param array $argv Raw $argv (script name in [0]).
     */
    final public static function run(array $argv = []): never
    {
        static::cli($argv);
    }

    /**
     * Brings the application up and blocks until shutdown. Called by the `run`
     * console command (so the boot sequence has already run — this does not
     * re-boot).
     *
     * With a {@see Component::http()} declared: builds one Swoole HTTP server and
     * attaches every other component as a supervised `addProcess`, co-terminating
     * with the server. MemoryWatcher is attached only when $watch is true
     * (`call run dev`). With no Http component: runs headless — a single component
     * in the foreground, or several under a small pcntl supervisor.
     *
     * @param bool $watch Attach the MemoryWatcher (development).
     */
    final public static function serve(bool $watch = false): never
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
            static::serveHttp($http, $companions, $watch, $logger);
        }

        static::serveHeadless($companions, $logger);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Web bundle: the Http component becomes the Swoole server; every other
     * component is attached with addProcess so the master supervises it and
     * stops it together with the server.
     *
     * @param list<Component> $companions
     */
    private static function serveHttp(Component $http, array $companions, bool $watch, LoggerInterface $logger): never
    {
        if (!extension_loaded('swoole')) {
            fwrite(STDERR, "[winter] `call run` with a web tier needs ext-swoole (pecl install swoole).\n");
            exit(1);
        }

        LoggerFactory::setContextStorage(new CoroutineContext());
        LoggerFactory::setDefaultChannel('http');

        $router = Router::fromScan(Kernel::$pathRoot);
        $router->static(Kernel::$pathPublic);

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_PROC);
        Runtime::boot(RuntimeMode::Swoole);

        $server = new \Swoole\Http\Server($http->host, $http->port);
        $server->set(static::swooleConfig());

        $names = [];
        foreach ($companions as $companion) {
            $class = (string) $companion->class;
            $names[] = self::shortName($class);
            $server->addProcess(new \Swoole\Process(
                static function () use ($class): void {
                    // The bundle process turned runtime hooks on for the HTTP
                    // reactor; reset them so this child is a clean plain process
                    // and each component boots its own runtime inside start().
                    \Swoole\Runtime::enableCoroutine(false);
                    // The fork copies the parent's open fds; drop them so the
                    // component reconnects in place (see Process::afterFork()).
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

        if ($watch) {
            $memory = new MemoryWatcher();
            $memory->attach($server);
            $server->on('request', $memory->wrap($handler));
        } else {
            $server->on('request', $handler);
        }

        $logger->info(sprintf(
            'Application up: http://%s:%d%s%s',
            $http->host,
            $http->port,
            $names === [] ? '' : ' + [' . implode(', ', $names) . ']',
            $watch ? ' (dev/watch)' : ''
        ));

        $server->start();
        exit(0);
    }

    /**
     * No web tier: run the background components directly. One component runs in
     * the foreground (fully managed on its own); several are forked and reaped by
     * a small supervisor that forwards a stop signal to the whole group. Works
     * with or without ext-swoole (each component picks its own engine).
     *
     * @param list<Component> $companions
     */
    private static function serveHeadless(array $companions, LoggerInterface $logger): never
    {
        if ($companions === []) {
            fwrite(
                STDERR,
                "[winter] Nothing to run: components() is empty. Declare at least one "
                . "Component::http()/process()/daemon()/scheduler().\n"
            );
            exit(1);
        }

        if (count($companions) === 1) {
            $class = (string) $companions[0]->class;
            $logger->info('Application up (headless): ' . self::shortName($class));
            $class::start();
            exit(0);
        }

        if (!function_exists('pcntl_fork')) {
            fwrite(STDERR, "[winter] Running several headless components needs ext-pcntl.\n");
            exit(1);
        }

        $children = [];
        foreach ($companions as $companion) {
            $class = (string) $companion->class;
            $pid = pcntl_fork();
            if ($pid === -1) {
                fwrite(STDERR, "[winter] fork failed for {$class}.\n");
                exit(1);
            }
            if ($pid === 0) {
                if (extension_loaded('swoole')) {
                    \Swoole\Runtime::enableCoroutine(false);
                }
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

    private static function shortName(string $class): string
    {
        return new \ReflectionClass($class)->getShortName();
    }
}
