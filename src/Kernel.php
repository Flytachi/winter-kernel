<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel;

use Flytachi\Winter\Base\Log\LoggerRegistry;
use Flytachi\Winter\Thread\Thread;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Dotenv\Dotenv;
use Flytachi\Winter\Kernel\Core\KernelStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        ?LoggerInterface $logger = null
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

        // logging
        LoggerRegistry::setInstance($logger !== null ? $logger : self::registryLogger());

        // thread
        self::bindThread();
    }

    private static function registryLogger(): LoggerInterface
    {
        if (!class_exists(Logger::class)) {
            return new NullLogger();
        }

        $allowedLevels = env('LOGGER_LEVEL_ALLOW');
        if (empty($allowedLevels) || trim($allowedLevels) === '') {
            return new NullLogger();
        }

        $allowedLevels = array_map('trim', explode(',', $allowedLevels));
        $levelMap = [
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'NOTICE' => Level::Notice,
            'WARNING' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL' => Level::Critical,
            'ALERT' => Level::Alert,
            'EMERGENCY' => Level::Emergency,
        ];

        $allowedLevels = array_map(fn($level) => $levelMap[strtoupper($level)] ?? null, $allowedLevels);
        $allowedLevels = array_filter($allowedLevels);
        if (empty($allowedLevels)) {
            return new NullLogger();
        }

        // Logger
        $logger = new Logger('Kernel');
        $maxFiles = (int) env('LOGGER_FILE_MAX', 0);

        // stdout (local) / syslog (docker)
        $syslog = env('LOGGER_SYSLOG');
        $useSyslog = ($syslog !== null && $syslog !== '')
            ? filter_var($syslog, FILTER_VALIDATE_BOOLEAN)
            : file_exists('/.dockerenv');
        if ($useSyslog) {
            $formatter = new LineFormatter(
                format: "%channel%.%level_name%: %message% %context% %extra%",
                dateFormat: env('LOGGER_LINE_DATE_FORMAT', 'Y-m-d H:i:s P'),
                allowInlineLineBreaks: false,
                ignoreEmptyContextAndExtra: true
            );
            $outputHandler = new SyslogHandler('winter', LOG_USER, Level::Debug);
        } else {
            $formatter = new LineFormatter(
                dateFormat: env('LOGGER_LINE_DATE_FORMAT', 'Y-m-d H:i:s P'),
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true
            );
            $outputHandler = new StreamHandler('php://stdout');
        }
        $outputHandler->setFormatter($formatter);
        $logger->pushHandler(new FilterHandler($outputHandler, $allowedLevels, Level::Emergency));

        // file — only if LOGGER_FILE_MAX > 0
        if ($maxFiles > 0) {
            $fileHandler = new RotatingFileHandler(
                self::$pathStorageLog . '/frame.log',
                maxFiles: $maxFiles,
                dateFormat: env('LOGGER_FILE_DATE_FORMAT', 'Y-m-d')
            );
            $fileHandler->setFormatter(new LineFormatter(
                dateFormat: env('LOGGER_LINE_DATE_FORMAT', 'Y-m-d H:i:s P'),
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true
            ));
            $logger->pushHandler(new FilterHandler($fileHandler, $allowedLevels, Level::Emergency));
        }

        return $logger;
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
    }
}
