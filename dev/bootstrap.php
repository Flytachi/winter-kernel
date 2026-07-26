<?php

declare(strict_types=1);

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\K2\App\Component;
use Flytachi\Winter\K2\Application;
use Flytachi\Winter\K2\Http\Cors;
use Flytachi\Winter\K2\Http\Health\Health;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Plugin;

require __DIR__ . '/vendor/autoload.php';

/**
 * Boot — application bootstrap class.
 *
 * Extend BaseBoot and override only the hooks you need.
 * All hooks are optional — omit them to use framework defaults.
 *
 * Boot order (called automatically by every entry point):
 *   1. configure()  — Kernel::init(), paths, .env, logging
 *   2. DI scan      — auto-discovers #[Singleton] / #[Request] / #[Transient]
 *   3. providers()  — service providers and manual bindings
 *   4. channels()   — custom log channels
 *   5. plugins()    — route-prefixed sub-applications
 *   6. httpCors()   — global CORS policy
 *   7. health()     — /actuator endpoints
 *
 * Entry points (call one from each runtime file):
 *   Boot::web()            public/index.php  — FPM
 *   Boot::swoole()         server.php        — Swoole HTTP server
 *   Boot::cli($argv)       call              — CLI console
 *   Boot::executor($argv)  wKernelExecutor   — thread / job runner
 */
class Boot extends Application
{
    /**
     * Components — what this application is made of.
     *
     * `call run` / `call run dev` bring these up: the Http one becomes the Swoole
     * server, the rest run beside it (addProcess). Remove Http to run headless.
     *
     * @return list<Component>
     */
    protected static function components(): array
    {
        return [
            Component::http(port: 8000),
            // Component::process(\Main\KernelSys::class),
            // Component::scheduler(),
        ];
    }

    /**
     * Kernel — paths, .env, logging, timezone.
     *
     * All parameters are optional; omitted ones are derived from $pathRoot.
     * Logging is configured entirely via .env — see LOG_* variables below.
     *
     * Paths:
     *   pathRoot            Project root                      (default: cwd)
     *   pathEnv             .env file location                (default: $pathRoot/.env)
     *   pathPublic          Web-accessible directory          (default: $pathRoot/public)
     *   pathResource        View / template directory         (default: $pathRoot/resources)
     *   pathStorage         Writable storage root             (default: $pathRoot/storage)
     *   pathStorageLog      Log files directory               (default: $pathStorage/logs)
     *   pathStorageCache    Cache files directory             (default: $pathStorage/cache)
     *   pathStorageRunnable Runnable task files               (default: $pathStorage/runnable)
     *
     * .env logging variables:
     *   LOG_LEVEL=info          Minimum severity: DEBUG|INFO|NOTICE|WARNING|ERROR|...
     *                           Empty → logging disabled (NullLogger for all channels)
     *   LOG_FORMAT=line         Output format: line | json
     *   LOG_OUTPUT=auto         Destination: auto | stdout | stderr | syslog | file | null
     *                             auto — Docker/K8s → syslog, Swoole → stdout, FPM/CLI → stderr
     *   LOG_FILE=               Absolute path when LOG_OUTPUT=file
     *   LOG_FILE_MAX=30         Number of daily rotating files to keep
     *   LOG_SYSLOG_IDENT=winter Program identity tag in syslog (journalctl -t winter)
     *
     * Per-channel overrides (LOG_{CHANNEL}_* takes priority over global):
     *   LOG_HTTP_LEVEL=warning
     *   LOG_HTTP_OUTPUT=file
     *   LOG_HTTP_FILE=/var/log/app/http.log
     *   LOG_CLI_OUTPUT=stderr
     *   LOG_SYS_OUTPUT=syslog
     */
    protected static function configure(): void
    {
        Kernel::init(pathRoot: __DIR__);
    }

    /**
     * DI — service providers and manual bindings.
     *
     * Called after the Scanner auto-discovers #[Singleton] / #[Request] / #[Transient].
     * Use this hook to bind interfaces to implementations, register factories,
     * or set named scalar values that cannot be expressed via attributes.
     *
     * Service providers (group related bindings):
     *   $c->register(AppServiceProvider::class);
     *   $c->register(DatabaseServiceProvider::class);
     *
     * Manual bindings:
     *   $c->singleton(CacheInterface::class, RedisCache::class);
     *   $c->request(AuthContext::class);
     *   $c->transient(QueryBuilder::class);
     *   $c->bind(MailerInterface::class, fn(Container $c) =>
     *       new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class))
     *   );
     *
     * Named scalar values (inject via #[Inject('config.timeout')]):
     *   $c->set('config.timeout', (int) env('APP_TIMEOUT', 30));
     *   $c->set('app.name', env('APP_NAME', 'Winter'));
     */
    protected static function providers(Container $c): void
    {
        // $c->register(AppServiceProvider::class);
    }

    /**
     * Logging — additional channels beyond the built-in sys / http / cli.
     *
     * Each channel reads LOG_{NAME}_* env vars with the same fallback chain
     * as the built-in channels. Channel name is lowercase by convention.
     *
     *   Kernel::channel('job');
     *   Kernel::channel('daemon');
     *
     * Usage in application code:
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
        // Kernel::channel('job');
    }

    /**
     * CORS — global Cross-Origin policy.
     *
     * Applied to every response (including 404 / 500) before route dispatch.
     * Per-route overrides are available via #[CrossOrigin] on controller class or method.
     *
     *   Cors::configure(
     *       origins:       ['https://app.example.com'],
     *       allowHeaders:  ['Content-Type', 'Authorization', 'X-Request-Id'],
     *       exposeHeaders: ['X-Request-Id'],
     *       credentials:   true,
     *       maxAge:        3600,
     *   );
     *
     * Empty origins array → wildcard '*' (any origin allowed).
     */
    protected static function httpCors(): void
    {
        // Cors::configure();
    }

    /**
     * Health / Actuator — diagnostic endpoints under /actuator.
     *
     * Endpoints (GET):
     *   /actuator            — full aggregated report
     *   /actuator/health     — up | degraded | down
     *   /actuator/info       — PHP version, SAPI, framework meta
     *   /actuator/metrics    — CPU, memory, disk, opcache, uptime
     *   /actuator/env        — custom env values
     *   /actuator/loggers    — active channels and levels
     *   /actuator/mappings   — registered route table
     *
     * Default (built-in indicator, open access):
     *   Health::configure();
     *
     * Custom indicator + middleware guard:
     *   Health::configure(
     *       indicator:  App\Health\AppHealthIndicator::class,
     *       middleware: App\Http\Middleware\InternalOnlyMiddleware::class,
     *   );
     */
    protected static function health(): void
    {
        // Health::configure();
    }

    /**
     * Plugins — route-prefixed sub-applications.
     *
     * Each plugin's src/ directory is scanned for controllers automatically.
     * No extra wiring required — routes are discovered on scan.
     *
     *   Plugin::registry('acme/auth-plugin',    '/auth');
     *   Plugin::registry('acme/billing-plugin', '/billing');
     *
     * Parameters:
     *   package   Composer package name  (e.g. 'acme/billing')
     *   prefix    URL prefix             (e.g. '/billing')
     *   required  Throw if not installed (default: true)
     */
    protected static function plugins(): void
    {
        // Plugin::registry('', '');
    }

    /**
     * Swoole server settings passed to \Swoole\Http\Server::set().
     *
     * Override to tune concurrency, request limits, and other Swoole options.
     * Return an empty array to use Swoole defaults.
     *
     *   return [
     *       'worker_num'        => swoole_cpu_num() * 2,
     *       'max_request'       => 5000,
     *       'max_request_grace' => 500,
     *   ];
     */
    public static function swooleConfig(): array
    {
        return [];
    }
}
