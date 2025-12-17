<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Edo\Declaration;
use Flytachi\Winter\Edo\Entity\RepositoryInterface;
use Flytachi\Winter\Edo\Mapping\Attributes\Entity\Table as EntityTable;
use Flytachi\Winter\Edo\Mapping\Structure\Table;
use Flytachi\Winter\Edo\Mapping\Tools\ColumnMapping;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

class EdoMapping
{
    public static function scanningDeclaration(): Declaration
    {
        $resources = Mapping::scanProjectFiles();
        $reflectionClasses = Mapping::scanRefClasses($resources, RepositoryInterface::class);
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
