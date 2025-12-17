<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory;

use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Factory\Middleware\MiddlewareInterface;
use Flytachi\Winter\Kernel\Stereotype\ControllerInterface;
use Flytachi\Winter\Mapping\Annotation\RequestMapping;
use Flytachi\Winter\Mapping\Declaration\MappingDeclaration;
use Flytachi\Winter\Mapping\Declaration\MappingDeclarationItem;
use Flytachi\Winter\Mapping\MappingRequestInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionUnionType;

class Mapping
{
    /**
     * @return array
     */
    public static function scanProjectFiles(): array
    {
        return scanFindAllFile(Kernel::$pathRoot, 'php', [
            Kernel::$pathRoot . '/vendor'
        ]);
    }

    /**
     * @param array $resources
     * @param class-string|null $interface
     * @return array<ReflectionClass>
     */
    public static function scanRefClasses(array $resources, ?string $interface = null): array
    {
        $reflectionClasses = [];
        foreach ($resources as $resource) {
            $className = ucfirst(str_replace(
                '.php',
                '',
                str_replace('/', '\\', str_replace(Kernel::$pathRoot . '/', '', $resource))
            ));

            try {
                $reflectionClass = new ReflectionClass($className);
                if ($interface === null || $reflectionClass->implementsInterface($interface)) {
                    $reflectionClasses[] = $reflectionClass;
                }
            } catch (\ReflectionException $ex) {
            }
        }
        return $reflectionClasses;
    }

    /**
     * @return MappingDeclaration
     */
    public static function scanningDeclaration(): MappingDeclaration
    {
        $resources = self::scanProjectFiles();
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
            // class annotation
            $groupAnnotation = $reflectionClass->getAttributes(RequestMapping::class);
            if (isset($groupAnnotation[0])) {
                $groupAnnotation = $groupAnnotation[0];
                /** @var MappingRequestInterface $mappingGroup */
                $mappingClass = $groupAnnotation->newInstance();
            } else {
                $mappingClass = null;
            }

            // class middleware annotations
            $middlewaresClass = [];
            $groupAnnotationMiddleware = $reflectionClass->getAttributes(
                MiddlewareInterface::class,
                ReflectionAttribute::IS_INSTANCEOF
            );
            foreach ($groupAnnotationMiddleware as $annotationMiddleware) {
                $middlewaresClass[] = $annotationMiddleware->getName();
            }

            // method annotation
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

        $declaration->sorting();
        return $declaration;
    }
}
