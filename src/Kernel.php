<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\K2\Core\KernelStore;
use Flytachi\Winter\Thread\Thread;
use Flytachi\Winter\Logger\Context\ProcessContext;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Logger\LoggerManager;
use Monolog\Level;
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

        defined('SERVER_SCHEME') or define('SERVER_SCHEME', (
                $_SERVER['REQUEST_SCHEME'] ?? 'http') . "://" . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
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
        self::bindThread();
    }

    private static function bootLogger(): void
    {
        $levelStr = trim((string) env('LOG_LEVEL', ''));

        $null = [
            'level' => Level::Debug,
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
        $levelStr = strtoupper((string) (env($prefix . 'LEVEL') ?? env('LOG_LEVEL', 'info')));

        $rawOutput = (string) (env($prefix . 'OUTPUT') ?? env('LOG_OUTPUT', 'auto'));
        $output    = self::resolveOutput($rawOutput);

        $filePath = env($prefix . 'FILE') ?? env('LOG_FILE');
        if ($output === 'file' && empty($filePath)) {
            $filePath = self::$pathStorageLog . '/' . $channel . '.log';
        }

        return [
            'level'        => Level::fromName($levelStr),
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

    private static function bindThread(): void
    {
        // thread runner
        $pathBinCustom = env('WINTER_THREAD_RUNNER', '');
        if (!empty($pathBinCustom) && file_exists($pathBinCustom)) {
            Thread::bindRunner($pathBinCustom);
        } else {
            $pathBin = self::$pathRoot . '/vendor/bin/wKernelExecutor';
            if (!file_exists($pathBin)) {
                $pathBin = self::$pathRoot . '/vendor/bin/wExecutor';
                if (!file_exists($pathBin)) {
                    return;
                }
            }
            Thread::bindRunner($pathBin);
        }

        // thread binary path
        $binaryPath = env('WINTER_BINARY_PATH', '');
        if (!empty($binaryPath)) {
            Thread::bindBinaryPath($binaryPath);
        }

        // thread security
        $key = env('WINTER_KEY', '');
        if (!empty($key)) {
            Thread::bindSerSecurity($key);
        }

        // thread payload mode
        if (extension_loaded('shmop')) {
            Thread::bindPayloadMode(Thread::PAYLOAD_SHM);
        }
    }
}
