<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionException;

/**
 * Scans the project for custom exception handlers (@ControllerAdvice pattern)
 * and routes Throwables to the matching handler at runtime.
 *
 * A handler is any class that:
 *   - implements ResponseExceptionInterface
 *   - carries #[AdviceException(...)] attribute
 *
 * Usage:
 *   ExceptionWrapper::configure(__DIR__);   // once, at application bootstrap
 *   $response = ExceptionWrapper::wrap($e); // at request time
 *
 * If not configured, falls back to ExceptionResponseBase for all Throwables.
 *
 * Specific handlers (with exception class names) are tried first.
 * Catch-all handlers (without class names) are tried last.
 */
final class ExceptionWrapper
{
    /** @var list<array{className: string, exceptions: string[]}> | null */
    private static ?array $handlers = null;

    private static ?string $rootDir = null;

    private function __construct()
    {
    }

    /**
     * Set the project root to scan for #[AdviceException] handlers.
     * Call once during application bootstrap (e.g., in Router::fromScan).
     * Resets the handler cache.
     */
    public static function configure(string $rootDir): void
    {
        self::$rootDir  = $rootDir;
        self::$handlers = null;
    }

    /**
     * Wrap a Throwable in the most specific matching exception response.
     * Falls back to ExceptionResponseBase if no custom handler is found.
     */
    public static function wrap(\Throwable $throwable): ResponseExceptionInterface
    {
        foreach (self::handlers() as $handler) {
            if (empty($handler['exceptions'])) {
                return new $handler['className']($throwable);
            }

            foreach ($handler['exceptions'] as $exClass) {
                if ($throwable instanceof $exClass) {
                    return new $handler['className']($throwable);
                }
            }
        }

        return new ExceptionResponseBase($throwable);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @return list<array{className: string, exceptions: string[]}> */
    private static function handlers(): array
    {
        if (self::$handlers !== null) {
            return self::$handlers;
        }

        if (self::$rootDir === null) {
            return self::$handlers = [];
        }

        return self::$handlers = self::scan(self::$rootDir);
    }

    /** @return list<array{className: string, exceptions: string[]}> */
    private static function scan(string $rootDir): array
    {
        $loaders      = ClassLoader::getRegisteredLoaders();
        $loader       = reset($loaders);
        $namespaceMap = $loader->getPrefixesPsr4();

        $specific = [];  // handlers for specific exception classes (tried first)
        $catchAll = [];  // handlers without class filter (tried last)

        foreach (self::findPhpFiles($rootDir) as $path) {
            $className = self::pathToClassName($path, $namespaceMap);
            if ($className === null || !class_exists($className)) {
                continue;
            }

            try {
                $ref = new ReflectionClass($className);

                if (
                    $ref->isAbstract()
                    || !$ref->implementsInterface(ResponseExceptionInterface::class)
                ) {
                    continue;
                }

                $attrs = $ref->getAttributes(AdviceException::class);
                if (empty($attrs)) {
                    continue;
                }

                /** @var AdviceException $advice */
                $advice = $attrs[0]->newInstance();
                $entry  = [
                    'className'  => $className,
                    'exceptions' => $advice->exceptionClassNames,
                ];

                if (empty($advice->exceptionClassNames)) {
                    $catchAll[] = $entry;
                } else {
                    $specific[] = $entry;
                }
            } catch (ReflectionException) {
            }
        }

        return array_merge($specific, $catchAll);
    }

    /** @return list<string> absolute paths, vendor excluded */
    private static function findPhpFiles(string $dir): array
    {
        $vendor = realpath($dir . '/vendor');
        $files  = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $real = $file->getRealPath();
            if ($vendor && str_starts_with($real, $vendor . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $files[] = $real;
        }

        return $files;
    }

    /** @param array<string, list<string>> $namespaceMap */
    private static function pathToClassName(string $realPath, array $namespaceMap): ?string
    {
        foreach ($namespaceMap as $prefix => $paths) {
            foreach ($paths as $basePath) {
                $baseReal = realpath($basePath);
                if ($baseReal === false) {
                    continue;
                }
                if (str_starts_with($realPath, $baseReal . DIRECTORY_SEPARATOR)) {
                    $relative = substr($realPath, strlen($baseReal) + 1);
                    $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                    $relative = substr($relative, 0, -4);
                    return $prefix . $relative;
                }
            }
        }
        return null;
    }
}
