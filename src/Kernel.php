<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\K2\Core\KernelStore;
use Flytachi\Winter\Thread\Launch\CliLauncher;
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

        // thread
        Thread::bindLauncher(CliLauncher::adaptive(
            secret: env('WINTER_KEY', ''),
            runnerPath: self::threadRunnerPath(),
        ));
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
                channels: ['sys' => $null, 'http' => $null, 'cli' => $null],
            ));
            return;
        }

        LoggerFactory::setManager(new LoggerManager(
            contextStorage: new ProcessContext(),
            channels: [
                'sys'  => self::buildChannelConfig('sys'),
                'http' => self::buildChannelConfig('http'),
                'cli'  => self::buildChannelConfig('cli'),
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

        $filePath = env($prefix . 'FILE') ?? env('LOG_FILE');
        if ($output === 'file' && empty($filePath)) {
            $filePath = self::$pathStorageLog . '/' . $channel . '.log';
        }

        return [
            'level'        => $levelStr,
            'format'       => (string) (env($prefix . 'FORMAT') ?? env('LOG_FORMAT', 'line')),
            'output'       => $output,
            'file_path'    => $filePath ? (string) $filePath : null,
            'file_max'     => (int) (env($prefix . 'FILE_MAX') ?? env('LOG_FILE_MAX', 30)),
            'syslog_ident' => (string) (env($prefix . 'SYSLOG_IDENT') ?? env('LOG_SYSLOG_IDENT', 'winter')),
        ];
    }

    private static function resolveOutput(string $raw): string
    {
        if ($raw !== 'auto') {
            return $raw;
        }
        if (getenv('KUBERNETES_SERVICE_HOST') !== false || file_exists('/.dockerenv')) {
            return 'syslog';
        }
        return Runtime::isSwoole() ? 'stdout' : 'stderr';
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
