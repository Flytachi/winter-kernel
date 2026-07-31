<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2;

use Flytachi\Winter\K2\Core\KernelStore;
use Flytachi\Winter\K2\Process\ForkReset;
use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
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
        ?string $pathPublic = null,
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
            $pathPublic,
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

        // thread — route each launch by runtime: Swoole\Process inside a coroutine
        // (proc_open corrupts the reactor's fds there), proc_open everywhere else.
        Thread::bindLauncher(AdaptiveLauncher::adaptive(
            secret: env('WINTER_KEY', ''),
            runnerPath: self::threadRunnerPath(),
        ));

        // fork-safety — a forked daemon worker inherits the parent's DB sockets;
        // reset the pool in the child (Process::afterFork) so it reconnects fresh.
        ForkReset::register(static fn() => PpaConnectionPool::reset());
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
