<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel;

use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Kernel\Core\KernelStore;
use Flytachi\Winter\Kernel\Localization\Timezone;
use Flytachi\Winter\Kernel\Process\ForkReset;
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
        ?string $pathEnv = null,
        ?string $pathResource = null,
        ?string $pathStorage = null,
        ?string $pathStorageLog = null,
        ?string $pathStorageCache = null,
        ?string $pathStorageRunnable = null,
        bool $isTmpVolatile = true,
    ): void {
        defined('WINTER_STARTUP_TIME') or define('WINTER_STARTUP_TIME', microtime(true));
        parent::init(
            $pathRoot,
            $pathEnv,
            $pathResource,
            $pathStorage,
            $pathStorageLog,
            $pathStorageCache,
            $pathStorageRunnable,
            $isTmpVolatile,
        );

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

        // fork-safety — a forked daemon worker inherits the parent's DB sockets;
        // reset the pool in the child (Process::afterFork) so it reconnects fresh.
        ForkReset::register(static fn() => PpaConnectionPool::reset());
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
        ForkReset::register(static fn() => RedisPool::reset());
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

        LoggerFactory::setManager(new LoggerManager(
            contextStorage: new ProcessContext(),
            channels: [
                'sys'  => self::buildChannelConfig('sys'),
                'http' => self::buildChannelConfig('http'),
            ],
        ));

        LoggerFactory::setDefaultChannel('sys');
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

        $filePath = env($prefix . 'FILE') ?? env('LOG_FILE');
        if ($output === 'file' && empty($filePath)) {
            $filePath = self::$pathStorageLog . '/' . $channel . '.log';
        }

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
