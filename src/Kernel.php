<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel;

use Flytachi\Winter\Base\Log\LoggerRegistry;
use Flytachi\Winter\Thread\Thread;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
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
        ?string $pathMain = null,
        ?string $pathEnv = null,
        ?string $pathPublic = null,
        ?string $pathResource = null,
        ?string $pathStorage = null,
        ?string $pathStorageCache = null,
        ?string $pathStorageLog = null,
        ?string $pathFileMapping = null,
        ?LoggerInterface $logger = null
    ): void {
        defined('WINTER_STARTUP_TIME') or define('WINTER_STARTUP_TIME', microtime(true));
        parent::init(
            $pathRoot,
            $pathMain,
            $pathEnv,
            $pathPublic,
            $pathResource,
            $pathStorage,
            $pathStorageCache,
            $pathStorageLog,
            $pathFileMapping
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
        Thread::bindRunner(dirname(__DIR__) . '/vendor/bin/runner');
    }

    public static function info(): array
    {
        return json_decode(
            file_get_contents(dirname(__DIR__) . '/composer.json') ?: '',
            true
        ) ?? [];
    }

    public static function projectInfo(): ?array
    {
        if (isset(self::$pathRoot)) {
            return json_decode(
                file_get_contents(self::$pathRoot . '/composer.json') ?: '',
                true
            ) ?? [];
        } else {
            return null;
        }
    }

    private static function registryLogger(): LoggerInterface
    {
        if (!class_exists(Logger::class)) {
            return new NullLogger();
        }

//        $allowedLevels = env('LOGGER_LEVEL_ALLOW');
        $allowedLevels = 'DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL';
        if ($allowedLevels === null || trim($allowedLevels) === '') {
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

        // RotatingFileHandler
        $loggerStreamHandler = new RotatingFileHandler(
            self::$pathStorageLog . '/frame.log',
            maxFiles: env('LOGGER_MAX_FILES', 0),
            dateFormat: env('LOGGER_FILE_DATE_FORMAT', 'Y-m-d')
        );
        $loggerStreamHandler->setFormatter(new LineFormatter(
            dateFormat: env('LOGGER_LINE_DATE_FORMAT', 'Y-m-d H:i:s P'),
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true
        ));

        // FilterHandler
        $filterHandler = new FilterHandler($loggerStreamHandler, $allowedLevels, Level::Emergency);
        $logger->pushHandler($filterHandler);

        // --- stdout
        if (PHP_SAPI === 'cli-server') {
            $stdoutHandler = new StreamHandler('php://stdout');
            $stdoutHandler->setFormatter(new LineFormatter(
                dateFormat: env('LOGGER_LINE_DATE_FORMAT', 'Y-m-d H:i:s P'),
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true
            ));
            $logger->pushHandler($stdoutHandler);
        }

        return $logger;
    }
}
