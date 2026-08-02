<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\Kernel\Collector\ImplementorCollector;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Entity\Table as EntityTable;
use Flytachi\Winter\Kernel\Ppa\Mapping\ColumnMapping;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Table;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

final class PPAMapping
{
    /**
     * @return DbConfigInterface[]
     */
    public static function scanningConfigs(?string $rootDir = null): array
    {
        $collector = new ImplementorCollector(DbConfigInterface::class);
        Scanner::run($rootDir ?? Kernel::$pathRoot)->collect($collector)->execute();

        $configs = [];
        foreach ($collector->getResult() as $ref) {
            try {
                $configs[] = $ref->newInstance();
            } catch (ReflectionException) {
            }
        }
        return $configs;
    }

    public static function scanningDeclaration(?string $rootDir = null): Declaration
    {
        $collector = new ImplementorCollector(RepositoryInterface::class);
        Scanner::run($rootDir ?? Kernel::$pathRoot)->collect($collector)->execute();

        return self::scanDeclarationFilter($collector->getResult());
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
                $config = new ReflectionClass($repository->getDbConfigClassName())->newInstance();
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
