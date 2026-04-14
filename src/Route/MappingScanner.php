<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

use Composer\Autoload\ClassLoader;
use Flytachi\Winter\K2\Route\Annotation\AbstractMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\ControllerInterface;
use Flytachi\Winter\K2\Stereotype\Middleware;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Scans PHP files for classes implementing ControllerInterface,
 * reads their #[GetMapping] / #[PostMapping] / … attributes,
 * and registers routes into a Router instance.
 */
class MappingScanner
{
    /**
     * Scan all PHP files under $rootDir, find controllers, register routes.
     *
     * @param string   $rootDir
     * @param Router   $router
     * @param string[] $exclude  Directories to skip (absolute paths)
     */
    public static function scan(string $rootDir, Router $router, array $exclude = []): void
    {
        $files   = self::findPhpFiles($rootDir, $exclude);
        $classes = self::resolveControllerClasses($files);

        foreach ($classes as $ref) {
            self::registerController($ref, $router);
        }
    }

    /**
     * Register routes from all currently declared classes implementing
     * ControllerInterface. Useful when controllers are defined inline (e.g. in tests).
     */
    public static function scanDeclared(Router $router): void
    {
        foreach (get_declared_classes() as $className) {
            try {
                $ref = new ReflectionClass($className);
                if (!$ref->isAbstract() && $ref->implementsInterface(ControllerInterface::class)) {
                    self::registerController($ref, $router);
                }
            } catch (ReflectionException) {
            }
        }
    }

    // ── File discovery ────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function findPhpFiles(string $rootDir, array $exclude): array
    {
        $excludeReal = array_filter(array_map('realpath', $exclude));
        $files       = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();

            foreach ($excludeReal as $ex) {
                if (str_starts_with($realPath, $ex)) {
                    continue 2;
                }
            }

            $files[] = $realPath;
        }

        return $files;
    }

    // ── Class resolution via Composer PSR-4 ──────────────────────────────────

    /** @return list<ReflectionClass> */
    private static function resolveControllerClasses(array $files): array
    {
        $loaders      = ClassLoader::getRegisteredLoaders();
        $loader       = reset($loaders);
        $namespaceMap = $loader->getPrefixesPsr4();
        $classes      = [];

        foreach ($files as $realPath) {
            $className = self::pathToClassName($realPath, $namespaceMap);
            if ($className === null || !class_exists($className)) {
                continue;
            }

            try {
                $ref = new ReflectionClass($className);
                if (!$ref->isAbstract() && $ref->implementsInterface(ControllerInterface::class)) {
                    $classes[] = $ref;
                }
            } catch (ReflectionException) {
            }
        }

        return $classes;
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
                    return $prefix . substr($relative, 0, -4); // strip .php
                }
            }
        }
        return null;
    }

    // ── Route registration ────────────────────────────────────────────────────

    private static function registerController(ReflectionClass $ref, Router $router): void
    {
        // Class-level prefix via #[RequestMapping('/prefix')]
        $classPrefix = '';
        $classAttrs  = $ref->getAttributes(RequestMapping::class);
        if (!empty($classAttrs)) {
            $classPrefix = $classAttrs[0]->newInstance()->getUrl();
        }

        // Class-level middlewares
        $classMiddlewares = self::collectMiddlewares(
            $ref->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF)
        );

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $attrs = $method->getAttributes(AbstractMapping::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attrs as $attr) {
                /** @var AbstractMapping $mapping */
                $mapping    = $attr->newInstance();
                $httpMethod = $mapping->getMethod();

                $url = $classPrefix !== '' && $mapping->getUrl() !== ''
                    ? $classPrefix . '/' . $mapping->getUrl()
                    : ($classPrefix ?: $mapping->getUrl());

                $url = '/' . $url;

                $handler = [$ref->getName(), $method->getName()];

                $methodMiddlewares = self::collectMiddlewares(
                    $method->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF)
                );
                $middlewares = array_merge($classMiddlewares, $methodMiddlewares);

                if ($httpMethod !== null) {
                    $router->add($httpMethod, $url, $handler, $middlewares);
                } else {
                    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
                        $router->add($m, $url, $handler, $middlewares);
                    }
                }
            }
        }
    }

    /**
     * Store class + args (not instances) so the Router creates a fresh instance
     * per request — required for coroutine safety.
     *
     * @param  ReflectionAttribute[] $attrs
     * @return list<array{class: class-string, args: array}>
     */
    private static function collectMiddlewares(array $attrs): array
    {
        $result = [];
        foreach ($attrs as $attr) {
            $result[] = ['class' => $attr->getName(), 'args' => $attr->getArguments()];
        }
        return $result;
    }
}
