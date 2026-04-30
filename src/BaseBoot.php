<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Base\RuntimeMode;
use Flytachi\Winter\Console\Core;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\K2\Http\Adapter\FpmRequest;
use Flytachi\Winter\K2\Http\Adapter\FpmResponse;
use Flytachi\Winter\K2\Http\Adapter\SwooleRequest;
use Flytachi\Winter\K2\Http\Adapter\SwooleResponse;
use Flytachi\Winter\K2\Route\MemoryWatcher;
use Flytachi\Winter\K2\Route\Router;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Thread\Runnable;

/**
 * Application bootstrap base — Java Boot-style entry point.
 *
 * Extend in your project's bootstrap.php and override hooks as needed:
 *
 * ```php
 * class Boot extends BaseBoot
 * {
 *     protected static function configure(): void
 *     {
 *         Kernel::init(pathRoot: __DIR__);
 *     }
 *
 *     protected static function container(Container $c): void
 *     {
 *         $c->register(AppServiceProvider::class);
 *     }
 *
 *     protected static function cors(): void
 *     {
 *         Cors::configure(origins: ['https://app.example.com']);
 *     }
 * }
 * ```
 *
 * Entry points:
 * ```php
 * // public/index.php  (FPM)
 * Boot::web();
 *
 * // server.php  (Swoole)
 * Boot::swoole();
 *
 * // call  (CLI console)
 * Boot::cli($argv);
 *
 * // wKernelExecutor  (thread / job runner)
 * Boot::executor($argv);
 * ```
 */
abstract class BaseBoot
{
    // ── Hooks (override in your Boot class) ───────────────────────────────────

    /** Initialise the kernel — paths, .env, logging, timezone. */
    protected static function configure(): void
    {
        Kernel::init();
    }

    /** Register service providers and manual DI bindings. */
    protected static function providers(Container $c): void {}

    /** Register additional log channels via Kernel::channel(). */
    protected static function channels(): void {}

    /** Configure global CORS policy via Cors::configure(). */
    protected static function httpCors(): void {}

    /** Configure health/actuator endpoints via Health::configure(). */
    protected static function health(): void {}

    /** Register plugins via Plugin::registry(). */
    protected static function plugins(): void {}

    /**
     * Swoole HTTP server settings passed to Server::set().
     * Override to tune worker_num, max_request, etc.
     *
     * @return array<string, mixed>
     */
    protected static function swooleConfig(): array
    {
        return [];
    }

    // ── Entry points ──────────────────────────────────────────────────────────

    /**
     * FPM entry point.
     * Reads the request from PHP superglobals and writes the response via
     * http_response_code() / header() / echo.
     *
     * Routes are cached in production (DEBUG=false) and always rescanned in dev.
     */
    final public static function web(): never
    {
        self::boot();
        LoggerFactory::setDefaultChannel('http');

        $router = Router::resolve(Kernel::$pathRoot);
        $router->static(Kernel::$pathPublic);
        $router->handle(new FpmRequest(), new FpmResponse());

        exit(0);
    }

    /**
     * Swoole HTTP server entry point.
     * Boots the server, enables full coroutine hook, and starts listening.
     * Requires ext-swoole.
     *
     * @param string $host Listen address (default: 0.0.0.0)
     * @param int    $port Listen port    (default: 9501)
     */
    final public static function swoole(string $host = '0.0.0.0', int $port = 9501): never
    {
        self::boot();
        LoggerFactory::setDefaultChannel('http');

        $router = Router::fromScan(Kernel::$pathRoot);
        $router->static(Kernel::$pathPublic);

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Runtime::boot(RuntimeMode::Swoole);

        $server = new \Swoole\Http\Server($host, $port);
        $server->set(static::swooleConfig());

        $watcher = new MemoryWatcher();
        $watcher->attach($server);

        $server->on('request', $watcher->wrap(
            static function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($router): void {
                $router->handle(new SwooleRequest($req), new SwooleResponse($res));
            }
        ));

        $server->start();
        exit(0);
    }

    /**
     * CLI console entry point.
     * Dispatches $argv to the console command router.
     *
     * @param array $argv Raw $argv from the CLI script
     */
    final public static function cli(array $argv = []): never
    {
        self::boot();
        LoggerFactory::setDefaultChannel('cli');

        (new Core($argv))->run();

        exit(0);
    }

    /**
     * Thread / job executor entry point.
     * Reads a serialised Runnable from stdin or shared memory and executes it.
     * Used internally by the wKernelExecutor binary.
     *
     * @param array $argv Raw $argv from the executor binary
     */
    final public static function executor(array $argv = []): never
    {
        self::boot();
        LoggerFactory::setDefaultChannel('cli');
        $logger = LoggerFactory::getLogger('Executor');

        $options = getopt('', ['namespace::', 'name::', 'tag::', 'debug', 'shmkey::']);

        set_time_limit(0);
        ob_implicit_flush();
        ignore_user_abort(true);

        if (isset($options['debug'])) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        }

        // Read payload from shared memory or stdin
        if (isset($options['shmkey'])) {
            $shmKey = (int) $options['shmkey'];
            $shm = @shmop_open($shmKey, 'a', 0, 0);
            if ($shm === false) {
                fwrite(STDERR, "Error: Failed to open shared memory segment (key={$shmKey}).\n");
                exit(1);
            }
            $payload = shmop_read($shm, 0, shmop_size($shm));
            shmop_delete($shm);
            unset($shm);
        } else {
            $payload = stream_get_contents(STDIN);
        }

        if (empty($payload)) {
            $logger->emergency('No payload received');
            fwrite(STDERR, "Error: No payload received.\n");
            exit(1);
        }

        // Deserialize Runnable
        $runnable = function_exists('\Opis\Closure\serialize')
            ? \Opis\Closure\unserialize($payload, \Flytachi\Winter\Thread\Thread::getSerSecurity())
            : unserialize($payload);
        unset($payload);

        if (!$runnable instanceof Runnable) {
            $logger->emergency('Payload is not a valid Runnable object');
            fwrite(STDERR, "Error: The provided payload is not a valid Runnable object.\n");
            exit(1);
        }

        // Set process title
        if (function_exists('cli_set_process_title')) {
            $ns   = isset($options['namespace']) ? ($options['namespace'] . ' ') : '';
            $tag  = $options['tag'] ?? 'runnable';
            $name = $options['name'] ?? substr($runnable::class, strrpos($runnable::class, '\\') + 1);
            cli_set_process_title("Winter {$ns}-> {$name}@{$tag}");
        }

        // Parse --arg-key=value / --arg-key flags
        $customArgs = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--arg-')) {
                $content = substr($arg, 6);
                if (str_contains($content, '=')) {
                    [$key, $value] = explode('=', $content, 2);
                    $customArgs[$key] = $value;
                } else {
                    $customArgs[$content] = true;
                }
            }
        }

        try {
            $runnable->run($customArgs);
            exit(0);
        } catch (\Throwable $e) {
            $logger->alert($e->getMessage(), [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            fwrite(STDERR, "Uncaught exception: " . $e->getMessage() . "\n");
            fwrite(STDERR, $e->getTraceAsString() . "\n");
            exit(1);
        }
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private static function boot(): void
    {
        static::configure();

        $c = Container::init();

        Scanner::run(
            rootDir: Kernel::$pathRoot,
            cache: env('DEBUG', false) ? null : Kernel::$pathStorageCache . '/di.php',
        )
            ->collect(new DICollector($c))
            ->execute();

        static::providers($c);
        static::channels();
        static::plugins();
        static::httpCors();
        static::health();
    }
}
