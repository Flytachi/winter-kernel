<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table as EntityTable;
use Flytachi\Winter\K2\Ppa\Mapping\ColumnMapping;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\K2\Route\MappingScanner;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

class PPAMapping
{
    /**
     * @return DbConfigInterface[]
     */
    public static function scanningConfigs(?string $rootDir = null): array
    {
        $reflectionClasses = MappingScanner::scanImplementors(
            $rootDir ?? Kernel::$pathRoot,
            DbConfigInterface::class
        );
        $configs = [];
        foreach ($reflectionClasses as $rc) {
            try {
                $configs[] = $rc->newInstance();
            } catch (ReflectionException) {
            }
        }
        return $configs;
    }

    public static function scanningDeclaration(?string $rootDir = null): Declaration
    {
        $reflectionClasses = MappingScanner::scanImplementors(
            $rootDir ?? Kernel::$pathRoot,
            RepositoryInterface::class
        );
        return self::scanDeclarationFilter($reflectionClasses);
    }

    /**
     * @param array<ReflectionClass> $reflectionClasses
     * @return Declaration
     */
    private static function scanDeclarationFilter(array $reflectionClasses): Declaration
    {
        $declaration = new Declaration();

        foreach ($reflectionClasses as $reflectionClass) {
            try {
                /** @var RepositoryInterface $repository */
                $repository = $reflectionClass->newInstance();
                /** @var DbConfigInterface $config */
                $config = (new ReflectionClass($repository->getDbConfigClassName()))->newInstance();
                $config->setUp();

                $reflectionClassEntity = new ReflectionClass($repository->getEntityClassName());
                $columnMap = new ColumnMapping($config->getDriver());

                $annotationClassEntity = $reflectionClassEntity
                    ->getAttributes(EntityTable::class, ReflectionAttribute::IS_INSTANCEOF);
                if (empty($annotationClassEntity)) {
                    continue;
                }

                foreach ($reflectionClassEntity->getProperties() as $property) {
                    $columnMap->push($property);
                }
                $declaration->push($config, new Table(
                    name: $repository::$table,
                    columns: $columnMap->getColumns(),
                    schema: $repository->getSchema(),
                ));
            } catch (ReflectionException $ex) {
            }
        }

        return $declaration;
    }
}
