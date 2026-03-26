<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory;

use Composer\Autoload\ClassLoader;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Factory\Middleware\MiddlewareInterface;
use Flytachi\Winter\Kernel\Stereotype\ControllerInterface;
use Flytachi\Winter\Kernel\Stereotype\Plugin;
use Flytachi\Winter\Mapping\Annotation\RequestMapping;
use Flytachi\Winter\Mapping\Declaration\MappingDeclaration;
use Flytachi\Winter\Mapping\Declaration\MappingDeclarationItem;
use Flytachi\Winter\Mapping\MappingException;
use Flytachi\Winter\Mapping\MappingRequestInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use ReflectionUnionType;

class Mapping
{
    /**
     * @return array
     */
    public static function scanProjectFiles(?string $rootDir = null): array
    {
        return scanFindAllFile($rootDir ?: Kernel::$pathRoot, 'php', [
            Kernel::$pathRoot . '/vendor'
        ]);
    }

    /**
     * @param array $resources
     * @param class-string|null $interface
     * @return array<ReflectionClass>
     */
    public static function scanRefClasses(
        array $resources,
        ?string $interface = null
    ): array {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        $namespaceMap = $loader->getPrefixesPsr4();

        $reflectionClasses = [];

        foreach ($resources as $resource) {
            $realPath = realpath($resource);
            $className = null;

            foreach ($namespaceMap as $prefix => $paths) {
                foreach ($paths as $path) {
                    $realNamespacePath = realpath($path);

                    if ($realNamespacePath !== false && str_starts_with($realPath, $realNamespacePath)) {
                        $relativePart = str_replace([$realNamespacePath, '.php'], '', $realPath);
                        $relativePart = ltrim($relativePart, DIRECTORY_SEPARATOR);
                        $className = $prefix . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePart);
                        break 2;
                    }
                }
            }

            if (!$className || !class_exists($className)) {
                continue;
            }

            try {
                $reflectionClass = new ReflectionClass($className);
                if ($interface === null || $reflectionClass->implementsInterface($interface)) {
                    $reflectionClasses[] = $reflectionClass;
                }
            } catch (ReflectionException) {
            }
        }

        return $reflectionClasses;
    }

    /**
     * @return MappingDeclaration
     */
    public static function scanningDeclaration(?string $rootPath = null): MappingDeclaration
    {
        $resources = self::scanProjectFiles($rootPath);
        $reflectionClasses = self::scanRefClasses($resources, ControllerInterface::class);
        return self::scanDeclarationFilter($reflectionClasses);
    }

    /**
     * @param array<ReflectionClass> $reflectionClasses
     * @return MappingDeclaration
     */
    private static function scanDeclarationFilter(array $reflectionClasses): MappingDeclaration
    {
        $declaration = new MappingDeclaration();

        foreach ($reflectionClasses as $reflectionClass) {
            $mappingClass = null;
            $middlewaresClass = [];

            // plugin annotation
            if ($reflectionClass->isSubclassOf(Plugin::class)) {
                $pluginAnnotations = $reflectionClass->getAttributes(PluginMapping::class);

                foreach ($pluginAnnotations as $pluginAnnotation) {
                    /** @var PluginMapping $plugin */
                    $plugin = $pluginAnnotation->newInstance();
                    $mappingClass = new RequestMapping($plugin->url);
                    $pluginMiddlewares = [];
                    if ($plugin->middlewareClassName !== null) {
                        $pluginMiddlewares[] = $plugin->middlewareClassName;
                    }
                    try {
                        $pluginReflectionClass = new ReflectionClass($plugin->controllerClassName);
                        if ($pluginReflectionClass->implementsInterface(ControllerInterface::class)) {
                            self::declareMethodAnnotation(
                                declaration: $declaration,
                                reflectionClass: $pluginReflectionClass,
                                mappingClass: $mappingClass,
                                middlewaresClass: $pluginMiddlewares
                            );
                        }
                    } catch (\ReflectionException $ex) {
                    }
                }
            } else {
                // group class annotation
                $groupAnnotation = $reflectionClass->getAttributes(RequestMapping::class);
                if (isset($groupAnnotation[0])) {
                    $groupAnnotation = $groupAnnotation[0];
                    /** @var MappingRequestInterface $mappingGroup */
                    $mappingClass = $groupAnnotation->newInstance();
                }

                // class middleware annotations
                $groupAnnotationMiddleware = $reflectionClass->getAttributes(
                    MiddlewareInterface::class,
                    ReflectionAttribute::IS_INSTANCEOF
                );
                foreach ($groupAnnotationMiddleware as $annotationMiddleware) {
                    $middlewaresClass[] = $annotationMiddleware->getName();
                }

                // method annotation
                self::declareMethodAnnotation(
                    declaration: $declaration,
                    reflectionClass: $reflectionClass,
                    mappingClass: $mappingClass,
                    middlewaresClass: $middlewaresClass
                );
            }
        }

        $declaration->sorting();
        return $declaration;
    }

    /**
     * @param MappingDeclaration $declaration
     * @param ReflectionClass $reflectionClass
     * @param MappingRequestInterface|null $mappingClass
     * @param array $middlewaresClass
     * @return void
     * @throws ReflectionException
     * @throws MappingException
     */
    private static function declareMethodAnnotation(
        MappingDeclaration &$declaration,
        ReflectionClass $reflectionClass,
        ?MappingRequestInterface $mappingClass,
        array $middlewaresClass
    ): void {
        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->name != '__construct') {
                $annotations = $reflectionMethod->getAttributes(
                    MappingRequestInterface::class,
                    ReflectionAttribute::IS_INSTANCEOF
                );
                foreach ($annotations as $annotation) {
                    /** @var MappingRequestInterface $mapping */
                    $mapping = $annotation->newInstance();

                    // method middleware annotations
                    $middlewares = [];
                    $annotationMiddlewares = $reflectionMethod->getAttributes(
                        MiddlewareInterface::class,
                        ReflectionAttribute::IS_INSTANCEOF
                    );
                    foreach ($annotationMiddlewares as $annotationMiddleware) {
                        $middlewares[] = $annotationMiddleware->getName();
                    }

                    // method arguments
                    $arguments = [];
                    foreach ($reflectionMethod->getParameters() as $parameter) {
                        $type = $parameter->getType();
                        $typeInfo = null;

                        if ($type !== null) {
                            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

                            foreach ($types as $typeSub) {
                                if (!$typeSub->isBuiltin()) {
                                    $refEnum = new ReflectionEnum($typeSub->getName());
                                    if ($refEnum->isEnum()) {
                                        $typeInfo = [
                                            'name' => $refEnum->getName(),
                                            'backing' => $refEnum->getBackingType()?->getName(),
                                        ];
                                        break;
                                    }
                                }
                            }
                        }

                        $arguments[] = [
                            'name' => $parameter->getName(),
                            'typeInfo' => $typeInfo,
                        ];
                    }

                    $declarationItem = new MappingDeclarationItem(
                        $mapping->getCallback() ?: '',
                        ($mappingClass != null
                            ? trim($mappingClass->getUrl() . '/' . $mapping->getUrl(), '/')
                            : $mapping->getUrl()
                        ),
                        $reflectionClass->getName(),
                        $reflectionMethod->getName(),
                        $arguments,
                        [...$middlewaresClass, ...$middlewares]
                    );
                    $declaration->push($declarationItem);
                }
            }
        }
    }
}
