<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2;

use Flytachi\Winter\Console\Core;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\K2\Concurrent\Async\AsyncCollector;
use Flytachi\Winter\K2\Concurrent\Async\Proxy\ProxyFactory;
use Flytachi\Winter\K2\Http\Adapter\FpmRequest;
use Flytachi\Winter\K2\Http\Adapter\FpmResponse;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Response\ExceptionWrapper;
use Flytachi\Winter\K2\Old\Process\Core\WinterRunner;
use Flytachi\Winter\K2\Route\Router;
use Flytachi\Winter\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Application bootstrap base — Java Boot-style entry point.
 *
 * @deprecated Use {@see WinterApplication} instead. This legacy base and its
 *   protected config hooks (providers/channels/httpCors/health/plugins/swooleConfig)
 *   stay only for the transition and will be removed.
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
 * Boot::cli($argv);   // call              — CLI console
 * Boot::executor($argv); // wKernelExecutor — thread / job runner
 * ```
 *
 * The Swoole HTTP server is no longer a BaseBoot entry point — it is built by
 * {@see Application::serve()} from the declared components (`call run`).
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
     * Two built-in channels are always registered: http, sys.
     * Entry points switch the active channel automatically:
     *   - web() / swoole()    → 'http' (per-request, coroutine context)
     *   - cli() / executor()  → 'sys'  (everything else)
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
     * Built-in channels (http, sys) are registered automatically by Kernel::init().
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

    public static function base(?string $defaultChannelName = null): void
    {
        self::boot();
        if ($defaultChannelName !== null) {
            Kernel::channel($defaultChannelName);
            LoggerFactory::setDefaultChannel($defaultChannelName);
        }
    }

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
        $isHead   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
        $response = new FpmResponse($isHead);
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
     * The 'sys' log channel is activated so all log writes go to the system output.
     * To inject per-session fields into every log line:
     *   LoggerFactory::contextStorage()->set('job', 'import');
     *
     * @param array $argv Raw $argv from the CLI script (script name in [0])
     */
    final public static function cli(array $argv = []): never
    {
        self::boot();
        LoggerFactory::setDefaultChannel('sys');

        new Core($argv)->run();

        exit(0);
    }

    /**
     * Thread / job executor entry point.
     *
     * Deserializes a Runnable object from stdin (PAYLOAD_PIPE) or shared memory
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
        LoggerFactory::setDefaultChannel('sys');
        $options = getopt('', ['namespace::', 'name::', 'tag::', 'debug', 'detach', 'shmkey::']);
        exit(WinterRunner::adaptive()->execute($options));
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Runs the boot sequence once (kernel init, DI scan, providers, channels,
     * plugins, CORS, health). Every entry point calls this first. Protected so a
     * subclass entry point (e.g. {@see Application::serve()}) can reuse it.
     */
    protected static function boot(): void
    {
        self::$bootClass = static::class;
        static::configure();

        $c = Container::init();
        $debug = (bool) env('DEBUG', false);

        // Swaps classes carrying #[Async] for their generated proxies. Shares the
        // scan with DICollector and must run after it — that collector rebinds a
        // class to itself, which would undo the substitution.
        $async = new AsyncCollector(
            $c,
            ProxyFactory::forKernel($debug),
            $debug ? null : Kernel::$pathStorageVolatile . '/async.php',
        );

        Scanner::run(
            rootDir: Kernel::$pathRoot,
            cache: $debug ? null
                : Kernel::$pathStorageVolatile . '/di.php',
        )
            ->collect(new DICollector($c))
            ->collect($async)
            ->execute();

        $async->flush();

        // Default contextual logger: #[Autowired] LoggerInterface $logger resolves to a
        // logger named after the class it is injected into. Override in providers() by
        // re-registering contextual(LoggerInterface::class, …).
        $c->contextual(
            LoggerInterface::class,
            static fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app'),
        );

        static::providers($c);
        static::channels();
        static::plugins();
        static::httpCors();
        static::health();
    }
}
