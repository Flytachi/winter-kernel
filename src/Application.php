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

/**
 * Single application entry point — the framework's answer to a Java `main()`.
 *
 * Extend it once, declare what the application contains via {@see components()},
 * then route every runtime through {@see run()} from a single file:
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
 *             Component::http(port: 8000),   // web server (main)
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
 * {@see run()} dispatches on the first argument:
 *   - `start` → server mode: every component in ONE Swoole process (see {@see serve()});
 *   - anything else → console mode: the CLI (make / run / daemon / schedule / …).
 *
 * Server mode is a Swoole-only concept — one process, many concerns, like a JVM.
 * FPM cannot host it (it is not a persistent process): under FPM the web tier is
 * still served by {@see web()} per request, and the other components run as
 * standalone `call daemon|process|schedule` processes.
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
     * The single entry point. Pass raw $argv; the first argument selects the mode:
     *   - `start` → {@see serve()} (all components in one Swoole process);
     *   - otherwise → {@see cli()} (console command, then exit).
     *
     * @param array $argv Raw $argv (script name in [0]).
     */
    final public static function run(array $argv = []): never
    {
        if (($argv[1] ?? null) === 'start') {
            static::serve();
        }

        static::cli($argv);
    }

    /**
     * Server mode: boot once, then host every declared component in a single
     * Swoole process and block until shutdown.
     *
     * The one {@see Component::http()} entry becomes the HTTP server. Each
     * Process / Daemon / Scheduler entry is attached with `addProcess`, so the
     * Swoole master supervises it and terminates it together with the server.
     * Every companion runs exactly as if launched by a standalone
     * `call daemon|process|schedule`: runtime coroutine hooks are reset inside
     * the child and {@see ForkReset} gives it fresh connections, so a Daemon's
     * pcntl master and a Process's own `Coroutine\run` behave identically to solo.
     */
    final public static function serve(): never
    {
        if (!extension_loaded('swoole')) {
            fwrite(
                STDERR,
                "[winter] 'call start' needs ext-swoole. Install it, or launch components "
                . "individually: `call run`, `call daemon`, `call schedule`.\n"
            );
            exit(1);
        }

        static::boot();
        LoggerFactory::setContextStorage(new CoroutineContext());
        LoggerFactory::setDefaultChannel('http');

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

        if ($http === null) {
            throw new ApplicationConfigException(
                'serve() needs one Component::http() to host the bundle. To run a single '
                . 'background component, launch it directly: `call daemon|process|schedule ... start`.'
            );
        }

        $logger = LoggerFactory::getLogger(static::class);

        $router = Router::fromScan(Kernel::$pathRoot);
        $router->static(Kernel::$pathPublic);

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_PROC);
        Runtime::boot(RuntimeMode::Swoole);

        $server = new \Swoole\Http\Server($http->host, $http->port);
        $server->set(static::swooleConfig());

        // Companions are attached BEFORE start() — one supervised process each.
        foreach ($companions as $companion) {
            /** @var class-string<\Flytachi\Winter\K2\Process\Process> $class */
            $class = (string) $companion->class;
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

        $watcher = new MemoryWatcher();
        $watcher->attach($server);
        $server->on('request', $watcher->wrap(
            static function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($router): void {
                $request = new SwooleRequest($req);
                $isHead  = strtoupper($request->getMethod()) === 'HEAD';
                $router->handle($request, new SwooleResponse($res, $isHead));
            }
        ));

        $names = array_map(
            static fn(Component $c): string => (new \ReflectionClass((string) $c->class))->getShortName(),
            $companions
        );
        $logger->info(sprintf(
            'Application up: http://%s:%d%s',
            $http->host,
            $http->port,
            $names === [] ? '' : ' + [' . implode(', ', $names) . ']'
        ));

        $server->start();
        exit(0);
    }
}
