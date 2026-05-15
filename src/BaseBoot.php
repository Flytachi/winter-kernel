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
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Response\ExceptionWrapper;
use Flytachi\Winter\K2\Route\MemoryWatcher;
use Flytachi\Winter\K2\Route\Router;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Thread\Runnable;

/**
 * Application bootstrap base — Java Boot-style entry point.
 *
 * Extend in bootstrap.php, override only the hooks you need,
 * then call one entry point from each runtime file.
 *
 * Minimal setup:
 * ```
 * class Boot extends BaseBoot
 * {
 *     protected static function configure(): void
 *     {
 *         Kernel::init(pathRoot: __DIR__);
 *     }
 * }
 * ```
 *
 * Entry points:
 * ```
 * Boot::web();        // public/index.php  — FPM
 * Boot::swoole();     // server.php        — Swoole HTTP server
 * Boot::cli($argv);   // call              — CLI console
 * Boot::executor($argv); // wKernelExecutor — thread / job runner
 * ```
 *
 * Boot-time error handler:
 *   handleBootError($e, $response) is invoked from web() if anything throws
 *   above Router::handle() — DI scan failure, ambiguous route mapping, .env
 *   errors, etc. It mirrors Router::sendError() so boot-time and request-time
 *   errors flow through the same ExceptionWrapper pipeline. Override the hook
 *   to integrate Sentry, render a branded page, or change the fallback body.
 */
abstract class BaseBoot
{
    private static string $bootClass = '';

    /** Returns the concrete Boot class name set during boot(). */
    public static function getBootClass(): string
    {
        return self::$bootClass;
    }

    // ── Hooks (override in your Boot class) ───────────────────────────────────

    /**
     * Initialise the kernel — resolves paths, loads .env, configures logging,
     * timezone, error reporting, and thread runner.
     *
     * All parameters are optional — omitted ones are derived from $pathRoot:
     *
     * ```
     * protected static function configure(): void
     * {
     *     Kernel::init(
     *         pathRoot:            __DIR__,
     *         pathEnv:             __DIR__ . '/.env',
     *         pathPublic:          __DIR__ . '/public',
     *         pathStorage:         __DIR__ . '/storage',
     *     );
     * }
     * ```
     *
     * Logging is driven entirely by .env — no code changes needed for basic setup.
     * Three built-in channels are always registered: sys, http, cli.
     * Entry points switch the active channel automatically:
     *   - web() / swoole()    → 'http'
     *   - cli() / executor()  → 'cli'
     *
     * Global .env variables:
     *   LOG_LEVEL=info          Minimum severity (DEBUG|INFO|NOTICE|WARNING|ERROR|...)
     *   LOG_FORMAT=line         Output format: line | json
     *   LOG_OUTPUT=auto         Destination: auto | stdout | stderr | syslog | file | null
     *   LOG_FILE=               Absolute path when LOG_OUTPUT=file
     *   LOG_FILE_MAX=30         Number of daily rotating files to keep
     *   LOG_SYSLOG_IDENT=winter Program identity tag in syslog
     *
     * Per-channel overrides (prefix LOG_{CHANNEL}_):
     *   LOG_HTTP_LEVEL=warning
     *   LOG_HTTP_OUTPUT=file
     *   LOG_HTTP_FILE=/var/log/app/http.log
     */
    protected static function configure(): void
    {
        Kernel::init();
    }

    /**
     * Register service providers and manual DI bindings.
     *
     * Called after the Scanner auto-discovers #[Singleton] / #[Request] /
     * #[Transient] classes. Use this hook to bind interfaces to implementations,
     * register factories, or set named scalar values.
     *
     * ```
     * protected static function providers(Container $c): void
     * {
     *     $c->register(AppServiceProvider::class);
     *     $c->register(DatabaseServiceProvider::class);
     *
     *     // Manual bindings
     *     $c->singleton(CacheInterface::class, RedisCache::class);
     *     $c->bind(MailerInterface::class, fn($c) =>
     *         new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class))
     *     );
     *     $c->set('config.timeout', (int) env('APP_TIMEOUT', 30));
     * }
     * ```
     */
    protected static function providers(Container $c): void
    {
    }

    /**
     * Register additional log channels via Kernel::channel().
     *
     * Built-in channels (sys, http, cli) are registered automatically by Kernel::init().
     * Call this hook to add custom channels. Each channel reads LOG_{NAME}_* env vars
     * with the same fallback chain as the built-in channels.
     *
     * Rules:
     *   - Call AFTER configure() (already guaranteed by boot order).
     *   - Channel name is lowercase by convention; env prefix is uppercased automatically.
     *   - If a channel is requested but not registered, it falls back to the default channel.
     *
     * ```
     * protected static function channels(): void
     * {
     *     Kernel::channel('job');
     *     Kernel::channel('daemon');
     * }
     * ```
     *
     * Usage anywhere in application code:
     *   LoggerFactory::getLogger(MyJob::class, 'job')->info('started');
     *   LoggerFactory::channel('daemon')->warning('slow tick');
     *
     * .env for custom channels:
     *   LOG_JOB_LEVEL=debug
     *   LOG_JOB_OUTPUT=file
     *   LOG_JOB_FILE=/var/log/app/job.log
     *   LOG_JOB_FILE_MAX=7
     */
    protected static function channels(): void
    {
    }

    /**
     * Configure global CORS policy via Cors::configure().
     *
     * Applied to every response — including 404, 405, and 5xx — before route
     * dispatch. Per-route overrides are available via #[CrossOrigin] on any
     * controller class or method; method-level takes priority over class-level.
     *
     * ```
     * protected static function httpCors(): void
     * {
     *     Cors::configure(
     *         origins:       ['https://app.example.com', 'https://admin.example.com'],
     *         allowHeaders:  ['Content-Type', 'Authorization', 'X-Request-Id'],
     *         exposeHeaders: ['X-Request-Id'],
     *         credentials:   true,
     *         maxAge:        3600,
     *     );
     * }
     * ```
     *
     * Cors::configure() parameters:
     *   origins        string[]  Allowed origins. Empty → wildcard '*'.
     *   allowHeaders   string[]  Headers the browser may send (preflight).
     *   exposeHeaders  string[]  Headers exposed to the browser (response).
     *   credentials    bool      Send Access-Control-Allow-Credentials: true.
     *   maxAge         int       Preflight cache lifetime in seconds.
     */
    protected static function httpCors(): void
    {
    }

    /**
     * Configure health / actuator endpoints via Health::configure().
     *
     * Registers read-only diagnostic endpoints under /actuator.
     * All endpoints return JSON. Useful for load-balancer probes and monitoring.
     *
     * Endpoints (GET):
     *   /actuator            — full aggregated report
     *   /actuator/health     — overall status: up | degraded | down
     *                          degraded: ≥80% resource usage  |  down: ≥90% or connection failed
     *   /actuator/info       — PHP version, SAPI, framework version, project meta
     *   /actuator/metrics    — CPU load, memory, disk, opcache stats, uptime
     *   /actuator/env        — custom env values (override env() in your indicator)
     *   /actuator/loggers    — active log channels and their configured levels
     *   /actuator/mappings   — registered route table
     *
     * ```
     * protected static function health(): void
     * {
     *     // Default built-in indicator, open access:
     *     Health::configure();
     *
     *     // Custom indicator + middleware guard:
     *     Health::configure(
     *         indicator:  App\Health\AppHealthIndicator::class,
     *         middleware: App\Http\Middleware\InternalOnlyMiddleware::class,
     *     );
     * }
     * ```
     */
    protected static function health(): void
    {
    }

    /**
     * Register plugins via Plugin::registry().
     *
     * Registers Composer packages as route-prefixed sub-applications.
     * Each plugin's src/ directory is scanned for controllers automatically
     * by Router::fromScan() / Router::resolve() — no extra wiring required.
     *
     * ```
     * protected static function plugins(): void
     * {
     *     Plugin::registry('acme/auth-plugin',    '/auth');
     *     Plugin::registry('acme/billing-plugin', '/billing');
     * }
     * ```
     *
     * Plugin::registry() parameters:
     *   package  string  Composer package name  (e.g. 'acme/billing').
     *   prefix   string  URL prefix             (e.g. '/billing').
     *   required bool    Throw if package is not installed (default: true).
     */
    protected static function plugins(): void
    {
    }

    /**
     * Swoole HTTP server settings passed to \Swoole\Http\Server::set().
     *
     * Override to tune concurrency, request limits, SSL, and other Swoole options.
     * Return an empty array to use Swoole's built-in defaults.
     *
     * ```
     * protected static function swooleConfig(): array
     * {
     *     return [
     *         'worker_num'        => swoole_cpu_num() * 2,
     *         'max_request'       => 5000,
     *         'max_request_grace' => 500,
     *         'enable_coroutine'  => true,
     *     ];
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public static function swooleConfig(): array
    {
        return [];
    }

    /**
     * Last-resort handler for errors thrown before Router::handle() installs
     * its own try/catch — boot failures, DI scan errors, ambiguous routes, etc.
     *
     * Mirrors Router::sendError() so boot-time and request-time errors render
     * through the same ExceptionWrapper pipeline. Override in your Boot class
     * to customise (Sentry reporting, branded error page, etc.).
     */
    protected static function handleBootError(\Throwable $e, HttpResponse $response): void
    {
        try {
            LoggerFactory::getLogger(static::class)->alert(
                'Boot failure: ' . $e->getMessage(),
                ['exception' => $e]
            );
        } catch (\Throwable) {
            error_log(sprintf(
                '[winter-kernel] Boot failure: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        try {
            $exc  = ExceptionWrapper::wrap($e);
            $body = $exc->getBody();
            $response->status($exc->getHttpCode()->value);
            foreach ($exc->getHeader() as $key => $value) {
                $response->header($key, $value);
            }
            $response->end($body);
        } catch (\Throwable) {
            $response->status(500);
            $response->header('Content-Type', 'text/plain; charset=utf-8');
            $response->end('Internal Server Error');
        }
    }

    // ── Entry points ──────────────────────────────────────────────────────────

    /**
     * FPM entry point — one request per process lifecycle.
     *
     * Reads the HTTP request from PHP superglobals ($_SERVER, $_GET, $_POST,
     * $_FILES, php://input) and writes the response via http_response_code() /
     * header() / echo. No shared state between requests.
     *
     * Route caching:
     *   DEBUG=false → loads from storage/cache/mapping.php when it exists;
     *                 scans and writes cache on first boot after deployment.
     *   DEBUG=true  → always rescans (dev mode, no stale cache).
     *
     * Static files:
     *   GET requests whose URI maps to an existing file in Kernel::$pathPublic
     *   are served directly — skipping route dispatch entirely. In FPM+nginx
     *   setups nginx already handles this, so the check is a no-op in production
     *   if nginx is configured correctly.
     *
     * Request pipeline:
     *   1. Header::init()            — snapshot superglobals into the Header bag
     *   2. Locale::initFromRequest() — detect Accept-Language / locale cookie
     *   3. Static file check         — short-circuit for existing public files
     *   4. Global CORS headers       — applied before dispatch (covers 404/500 too)
     *   5. OPTIONS preflight         — returns 204 before handler invocation
     *   6. Route dispatch            — O(1) static map → chunked regex dynamic scan
     *   7. Per-route #[CrossOrigin]  — overrides global CORS if present
     *   8. Middleware before()       — run in declaration order
     *   9. Controller method         — resolved via ReflectionCache + ParameterResolver
     *  10. Middleware after()        — run in reverse order
     *  11. Response serialise        — Sendable::send() or ResponseEntity::ok()->send()
     *  12. Error handling            — ExceptionWrapper maps Throwable → HTTP response
     */
    final public static function web(): never
    {
        $response = new FpmResponse();
        try {
            self::boot();
            LoggerFactory::setDefaultChannel('http');

            $router = Router::resolve(Kernel::$pathRoot);
            $router->static(Kernel::$pathPublic);
            $router->handle(new FpmRequest(), $response);
        } catch (\Throwable $e) {
            self::handleBootError($e, $response);
        }

        exit(0);
    }

    /**
     * Swoole coroutine HTTP server entry point.
     *
     * Performs a single filesystem scan on startup — routes stay in memory for
     * the entire server lifetime. All requests share the same Router instance
     * via the on('request') callback; coroutine isolation keeps per-request
     * state separate.
     *
     * Requires ext-swoole. SWOOLE_HOOK_ALL is enabled automatically so that
     * all blocking I/O (PDO, cURL, file, sleep, …) is coroutine-friendly.
     *
     * Server configuration is supplied via swooleConfig() — override it in Boot:
     * ```
     * protected static function swooleConfig(): array
     * {
     *     return ['worker_num' => swoole_cpu_num() * 2, 'max_request' => 5000];
     * }
     * ```
     *
     * Request pipeline (per coroutine):
     *   1. Header::init()            — snapshot request headers into coroutine ctx
     *   2. Locale::initFromRequest() — detect Accept-Language / locale cookie
     *   3. Swoole ctx stamp          — record start time, method, URI
     *   4. Static file check         — serve files from Kernel::$pathPublic
     *   5. Global CORS headers       — applied before dispatch
     *   6. OPTIONS preflight         — returns 204 before handler invocation
     *   7. Route dispatch            — same pipeline as web()
     *   8–12. identical to web()
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
     *
     * Parses $argv and dispatches to the matching console command class under
     * Flytachi\Winter\Console\Command\{Name}. The first argument selects the
     * command; aliases defined in Console\Core::$aliases are resolved first.
     *
     * Built-in commands: Make, Run, Script, Thread, Help, Cfg, Serve, Complete.
     * Custom commands live in your project and are discovered via the scanner.
     *
     * Usage examples (from the 'call' binary):
     * ```
     * ./call make:controller UserController
     * ./call run MyDaemon
     * ./call help
     * ```
     *
     * The 'cli' log channel is activated so all log writes go to the CLI output.
     * To inject per-session fields into every log line:
     *   LoggerFactory::contextStorage()->set('job', 'import');
     *
     * @param array $argv Raw $argv from the CLI script (script name in [0])
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
     *
     * Deserialises a Runnable object from stdin (PAYLOAD_PIPE) or shared memory
     * (PAYLOAD_SHM, requires ext-shmop) and executes it in a child process
     * spawned by the Thread dispatcher.
     *
     * This method is called by the wKernelExecutor binary — you do not invoke it
     * directly. The binary is referenced by Thread::dispatch() internally.
     *
     * Payload sources (selected automatically by the dispatcher):
     *   PAYLOAD_PIPE — serialised Runnable written to the child's stdin pipe
     *   PAYLOAD_SHM  — serialised Runnable placed in a shared memory segment
     *                  (avoids fd conflicts in Swoole; requires ext-shmop)
     *
     * CLI flags accepted by the executor binary:
     *   --namespace=App   Process title namespace prefix
     *   --name=MyJob      Override the process title name (default: class short name)
     *   --tag=worker      Process title tag (default: 'runnable')
     *   --shmkey=1234     Read payload from SHM segment with this key instead of stdin
     *   --debug           Enable full error reporting in the child process
     *   --arg-key=value   Pass custom arguments to Runnable::run() as ['key' => 'value']
     *   --arg-flag        Pass boolean flag to Runnable::run() as ['flag' => true]
     *
     * @param array $argv Raw $argv from the wKernelExecutor binary
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
                fwrite(STDERR, "Error: Failed to open shared memory segment (key=$shmKey).\n");
                exit(1);
            }
            $payload = shmop_read($shm, 0, shmop_size($shm));
            shmop_delete($shm);
            unset($shm);
        } else {
            $payload = stream_get_contents(STDIN);
        }

        if (empty($payload)) {
            $logger->critical('No payload received');
            fwrite(STDERR, "Error: No payload received.\n");
            exit(1);
        }

        // Deserialize Runnable
        $runnable = function_exists('\Opis\Closure\serialize')
            ? \Opis\Closure\unserialize($payload, \Flytachi\Winter\Thread\Thread::getSerSecurity())
            : unserialize($payload);
        unset($payload);

        if (!$runnable instanceof Runnable) {
            $logger->critical('Payload is not a valid Runnable object');
            fwrite(STDERR, "Error: The provided payload is not a valid Runnable object.\n");
            exit(1);
        }

        // Set process title
        if (function_exists('cli_set_process_title')) {
            $ns   = isset($options['namespace']) ? ($options['namespace'] . ' ') : '';
            $tag  = $options['tag'] ?? 'runnable';
            $name = $options['name'] ?? substr($runnable::class, strrpos($runnable::class, '\\') + 1);
            cli_set_process_title("Winter $ns-> $name@$tag");
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
            $logger->critical($e->getMessage(), [
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
        self::$bootClass = static::class;
        static::configure();

        $c = Container::init();

        Scanner::run(
            rootDir: Kernel::$pathRoot,
            cache: env('DEBUG', false) ? null
                : Kernel::$pathStorageVolatile . '/di.php',
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
