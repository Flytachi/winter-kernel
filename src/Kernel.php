<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel;

use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Kernel\Core\KernelStore;
use Flytachi\Winter\Kernel\Localization\Timezone;
use Flytachi\Winter\Kernel\Process\BeforeFork;
use Flytachi\Winter\Kernel\Process\ForkReset;
use Flytachi\Winter\Kernel\Process\RuntimeShutdown;
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Thread\Launch\AdaptiveLauncher;
use Flytachi\Winter\Thread\Thread;
use Flytachi\Winter\Logger\Context\ProcessContext;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Logger\LoggerManager;
use Dotenv\Dotenv;

/**
 * Class Kernel
 *
 * @version 1.5
 * @author Flytachi
 */
final class Kernel extends KernelStore
{
    public static function init(
        ?string $pathRoot = null,
        ?string $pathResource = null,
    ): void {
        defined('WINTER_STARTUP_TIME') or define('WINTER_STARTUP_TIME', microtime(true));
        parent::init(
            $pathRoot,
            $pathResource,
        );

        self::importProcessEnvironment();
        Dotenv::createImmutable(self::$pathRoot)
            ->safeLoad();

        date_default_timezone_set(env('TIME_ZONE', 'UTC'));

        if (env('DEBUG', false)) {
            ini_set('error_reporting', E_ALL);
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
        } else {
            ini_set('error_reporting', 0);
            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
        }

        self::resolveLogDirectory();
        self::bootLogger();

        // thread — both backends spawn the same `php <runnerPath>` child; only the way
        // the shell is invoked differs. Inside a coroutine proc_open corrupts the
        // reactor's descriptors and Swoole\Process is refused while its async-io
        // threads are up, so the launcher shells out via Coroutine\System::exec();
        // everywhere else proc_open is used unchanged.
        Thread::bindLauncher(AdaptiveLauncher::adaptive(
            secret: env('WINTER_KEY', ''),
            runnerPath: self::threadRunnerPath(),
        ));

        // PPA is an optional package: an application without a database never installs
        // it, and the kernel must boot the same either way. Everything below is wiring
        // the package cannot do for itself — it reaches for none of the framework's
        // globals, which is what lets it be used (and tested) without one.
        if (DepSupport::has(Dep::Ppa)) {
            self::wirePpa();
        }

        if (DepSupport::has(Dep::Redis)) {
            self::wireRedis();
        }
    }

    /**
     * Hands PPA the three things it deliberately does not fetch on its own.
     *
     * Called only when the package is installed; see {@see DepSupport}.
     */
    private static function wirePpa(): void
    {
        PpaConnectionPool::setLogger(LoggerFactory::getLogger('PPA'));
        // The session timezone must follow the request, and the request's zone is
        // coroutine-local — reading PHP's engine global instead would let a request that
        // yielded on I/O pick up a concurrent request's zone.
        PpaConnectionPool::setTimezoneProvider(static fn(): string => Timezone::current());
        // Per-worker pool stats land in the runtime store so /actuator/health can read
        // across workers. Unhashed keys: the reader lists them and reads them back. A
        // provider, not a store: building one creates its directory, and an application
        // that never opens a pool must not end up with an empty runnable/ppa.pool/.
        PoolTelemetry::setStoreProvider(static fn() => KernelStore::runnable('ppa.pool', false));

        // fork-safety, both sides. The parent closes its own connections before it forks
        // — a child cannot drop an inherited PDO quietly: its destructor closes the
        // parent's server session — and refuses to fork mid-transaction. The child then
        // resets the pool (Process::afterFork) and reconnects fresh.
        // closeBeforeFork() and per-process telemetry came with winter-ppa 1.2; an older
        // ppa keeps its previous behaviour rather than failing every fork.
        if (method_exists(PpaConnectionPool::class, 'closeBeforeFork')) {
            BeforeFork::register(static fn() => PpaConnectionPool::closeBeforeFork());
        }
        ForkReset::register(static fn() => PpaConnectionPool::reset());

        // A pool's housekeeping timer — and the telemetry publisher, when the process
        // published — keep a process's Coroutine\run() open after the body is done; what
        // workerExit does for an HTTP worker, this does for a process.
        if (defined(PoolTelemetry::class . '::KIND_PROCESS')) {
            RuntimeShutdown::register(static fn() => PoolTelemetry::stop());
        }
        RuntimeShutdown::register(static fn() => PpaConnectionPool::shutdown());
    }

    /**
     * Hands winter-redis the two things it deliberately does not fetch on its own.
     *
     * Called only when the package is installed; see {@see DepSupport}.
     *
     * Shorter than {@see wirePpa()} because Redis needs less: there is no session
     * timezone to follow, and the pool publishes no cross-worker telemetry — its
     * numbers reach `/actuator/pools` through the health indicator, which reads
     * {@see RedisPool::stats()} from the worker that served the request.
     */
    private static function wireRedis(): void
    {
        RedisPool::setLogger(LoggerFactory::getLogger('Redis'));

        // fork-safety — the same hazard as for the DB pool, and worse to diagnose: a
        // fork copies descriptors, so a socket opened before it is physically shared
        // with the parent. Two processes writing commands into one socket corrupt the
        // protocol for both, and the symptom (`packets out of order`, a reply for
        // somebody else's request) points nowhere near the fork. `reset()` forgets the
        // inherited connections **without closing** them — closing would tear down the
        // parent's socket — and the child reopens lazily.
        //
        // The parent side closes its own connections first (see wirePpa()).
        // closeBeforeFork() came with a later winter-redis than the kernel requires; an
        // older one keeps its previous behaviour rather than failing every fork.
        if (method_exists(RedisPool::class, 'closeBeforeFork')) {
            BeforeFork::register(static fn() => RedisPool::closeBeforeFork());
        }
        ForkReset::register(static fn() => RedisPool::reset());

        // Same as for the DB pool: release the housekeeping timer when a process ends.
        RuntimeShutdown::register(static fn() => RedisPool::shutdown());
    }

    /**
     * Makes the variables the process was started with visible to `env()`, ahead of
     * `.env`: `LOG_LEVEL=debug php call run` wins over the file, as it does in Docker.
     *
     * `env()` reads `$_ENV`, and PHP fills `$_ENV` only when `variables_order` holds an
     * `E` — the php.ini of most installations says `GPCS`, an image without one says
     * `EGPCS`. Without the `E` a process variable was invisible, and one that was in
     * `.env` as well was lost altogether: Dotenv found it in `$_SERVER`, left it there,
     * and wrote nothing to `$_ENV`. The same application read its settings one way on
     * a laptop and another in a container.
     *
     * CLI only. Under PHP-FPM `getenv()` also carries the request's parameters — its
     * `HTTP_*` headers among them — and copying those would let a client set
     * "environment variables" with a header (httpoxy).
     */
    private static function importProcessEnvironment(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        foreach (getenv() as $name => $value) {
            $_ENV[$name] ??= $value;
        }
    }

    private static function bootLogger(): void
    {
        $levelStr = trim((string) env('LOG_LEVEL', ''));

        $null = [
            'level' => 'info',
            'format' => 'line',
            'output' => 'null',
            'file_path' => null,
            'file_max' => 0,
            'syslog_ident' => 'winter'
        ];

        if (empty($levelStr)) {
            LoggerFactory::setManager(new LoggerManager(
                contextStorage: new ProcessContext(),
                channels: ['sys' => $null, 'http' => $null],
            ));
            LoggerFactory::setDefaultChannel('sys');
            return;
        }

        $channels = [
            'sys'  => self::buildChannelConfig('sys'),
            'http' => self::buildChannelConfig('http'),
        ];
        if (in_array('file', array_column($channels, 'output'), true)) {
            self::assertLogDirectory();
        }

        LoggerFactory::setManager(new LoggerManager(
            contextStorage: new ProcessContext(),
            channels: $channels,
        ));

        LoggerFactory::setDefaultChannel('sys');
    }

    /**
     * Where file logs go: `LOG_DIR`, relative to the project root unless absolute,
     * `<root>/logs` without it. One directory, a file per channel in it — `sys.log`,
     * `http.log`.
     *
     * `LOG_FILE` and `LOG_<CHANNEL>_FILE` named a file, and one `LOG_FILE` sent both
     * channels into it interleaved. They are refused by name rather than ignored: logs
     * quietly going somewhere else after an upgrade are logs nobody finds.
     */
    private static function resolveLogDirectory(): void
    {
        foreach (['LOG_FILE', 'LOG_SYS_FILE', 'LOG_HTTP_FILE'] as $name) {
            if (env($name) !== null) {
                throw new \RuntimeException(
                    "{$name} is no longer read: set LOG_DIR to the directory the logs go to "
                    . '(a file per channel in it: sys.log, http.log).'
                );
            }
        }
        $dir = trim((string) env('LOG_DIR', ''));
        if ($dir !== '') {
            self::$pathLogs = rtrim(str_starts_with($dir, '/') ? $dir : self::$pathRoot . '/' . $dir, '/');
        }
    }

    /**
     * Makes the log directory, or stops the boot saying why it cannot be written: a
     * directory under `/var/log` usually needs to be made for the application's user
     * first, and the first log line is the wrong place to find that out.
     */
    private static function assertLogDirectory(): void
    {
        try {
            self::ensureDirectory(self::$pathLogs);
        } catch (\Throwable) {
            // Reported below together with an existing but read-only directory.
        }
        if (!is_dir(self::$pathLogs) || !is_writable(self::$pathLogs)) {
            throw new \RuntimeException(sprintf(
                'LOG_OUTPUT=file, but the log directory %s cannot be written by this user (%s). '
                . 'Create it with the right owner, or point LOG_DIR elsewhere.',
                self::$pathLogs,
                self::currentUser(),
            ));
        }
    }

    /**
     * Makes `.winter/`, or stops with why it cannot be written. The framework keeps
     * everything it writes there — process records and locks, the class-list and proxy
     * caches, the file cache — and without it a start failed far from the cause: `di
     * build` said a cache file "was not produced", and an application came up and lost
     * its components one by one.
     *
     * Usually the project folder belongs to another user than the one the application
     * runs as: a checkout owned by root, mounted into a container that runs as `winter`.
     *
     * @internal Called where the framework first writes: the application's start, `di build`.
     * @throws \RuntimeException
     */
    public static function assertWinterDirectory(): void
    {
        $dir = self::$pathWinter;
        try {
            self::ensureDirectory($dir);
        } catch (\Throwable) {
            // Reported below together with an existing but read-only folder.
        }
        if (is_dir($dir) && is_writable($dir)) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'The framework cannot write %s as this user (%s): it keeps its process records, '
            . 'locks and caches there. Give the user the project folder (chown -R), '
            . 'or create .winter/ owned by it.',
            $dir,
            self::currentUser(),
        ));
    }

    /** Who this process runs as, for messages about permissions. */
    private static function currentUser(): string
    {
        if (!function_exists('posix_geteuid')) {
            return get_current_user();
        }
        $uid = posix_geteuid();
        $name = posix_getpwuid($uid)['name'] ?? null;
        return $name !== null ? "{$name}, uid {$uid}" : "uid {$uid}";
    }

    /**
     * Register an additional channel from .env (LOG_{NAME}_* vars).
     * Call after Kernel::init() in bootstrap or entry point:
     *   Kernel::channel('job');
     *   Kernel::channel('daemon');
     */
    public static function channel(string $name): void
    {
        LoggerFactory::addChannel($name, self::buildChannelConfig($name));
    }

    private static function buildChannelConfig(string $channel): array
    {
        $prefix   = 'LOG_' . strtoupper($channel) . '_';
        $levelStr = strtolower((string) (env($prefix . 'LEVEL') ?? env('LOG_LEVEL', 'info')));

        $rawOutput = (string) (env($prefix . 'OUTPUT') ?? env('LOG_OUTPUT', 'auto'));
        $output    = self::resolveOutput($rawOutput);
        $format    = (string) (env($prefix . 'FORMAT') ?? env('LOG_FORMAT', 'line'));

        $filePath = $output === 'file' ? self::$pathLogs . '/' . $channel . '.log' : null;

        // ANSI colour — line format only, never JSON. LOG_COLOR = auto|always|never
        // (auto = colour only when the output is an interactive terminal, mirroring
        // Spring's `detect` / Postgres' PG_COLOR).
        $colorMode = strtolower((string) (env($prefix . 'COLOR') ?? env('LOG_COLOR', 'auto')));
        $color = $format === 'line' && match ($colorMode) {
            'always' => true,
            'never'  => false,
            default  => self::outputIsTty($output),
        };

        return [
            'level'        => $levelStr,
            'format'       => $format,
            'output'       => $output,
            'color'        => $color,
            'file_path'    => $filePath ? (string) $filePath : null,
            'file_max'     => (int) (env($prefix . 'FILE_MAX') ?? env('LOG_FILE_MAX', 30)),
            // Fixed syslog program tag — winter-logger requires the key; not a knob.
            'syslog_ident' => 'winter',
        ];
    }

    private static function resolveOutput(string $raw): string
    {
        // `auto` → stdout everywhere; whatever runs the process (orchestrator,
        // supervisor, terminal) captures stdout. Explicit values pass through.
        return $raw === 'auto' ? 'stdout' : $raw;
    }

    /** True when the resolved log output is an interactive terminal (for LOG_COLOR=auto). */
    private static function outputIsTty(string $output): bool
    {
        return match ($output) {
            'stdout' => defined('STDOUT') && stream_isatty(STDOUT),
            'stderr' => defined('STDERR') && stream_isatty(STDERR),
            default  => false,
        };
    }

    private static function threadRunnerPath(): string
    {
        $runner = env('WINTER_THREAD_RUNNER');
        if (!empty($runner) && file_exists($runner)) {
            return $runner;
        }
        return Kernel::$pathRoot . '/vendor/bin/wKernelRunner';
    }
}
