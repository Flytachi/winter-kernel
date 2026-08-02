<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping;

use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Additive\AttributeDbAdditive;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Constraint\AttributeDbConstraint;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Constraint\AttributeDbConstraintCheck;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Constraint\AttributeDbConstraintForeign;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Hybrid\AttributeDbHybrid;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Idx\AttributeDbIdx;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal\AttributeDbType;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Sub\AttributeDbSubType;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\ForeignKey;
use ReflectionAttribute;
use ReflectionProperty;

final class ColumnMapping
{
    /** @var Column[]  */
    private array $columns = [];

    public function __construct(
        private string $dialect = 'mysql'
    ) {
    }

    public function push(ReflectionProperty $property): void
    {
        $this->columns[] = $this->toColumn($property);
    }

    private function toColumn(ReflectionProperty $property): Column
    {
        /** @var ?AttributeDbType $attributeType */
        $attributeType = null;
        /** @var ?AttributeDbSubType $attributeTypeSub */
        $attributeTypeSub = null;
        /** @var Index[] $indexes */
        $indexes = [];
        /** @var ?ForeignKey $foreignKey */
        $foreignKey = null;
        /** @var ?CheckConstraint $checkConstraint */
        $checkConstraint = null;

        $types = $this->checkingType($property);
        $nullable = null;
        $default = null;


        foreach ($property->getAttributes(AttributeDb::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $this->prepareInstance(
                property: $property,
                attribute: $attribute,
                instance: $attribute->newInstance(),
                attributeType: $attributeType,
                attributeTypeSub: $attributeTypeSub,
                indexes: $indexes,
                foreignKey: $foreignKey,
                checkConstraint: $checkConstraint,
                types: $types,
                nullable: $nullable,
                default: $default,
            );
        }

        // type
        $type = empty($attributeType)
            ? Column::getPrimitiveSqlType($types, $this->dialect)
            : $attributeType->toSql($this->dialect);
        $type = empty($attributeTypeSub) ? $type
            : $attributeTypeSub->toSql($type, $this->dialect);

        $nullable = $nullable ?? in_array('null', $types);
        $default = $default ?? ($property->hasDefaultValue()
            ? ($property->isDefault()
                ? match (getType($property->getDefaultValue())) {
                    'NULL' => in_array('null', $types)
                        ? 'NULL'
                        : null,
                    'boolean' => $property->getDefaultValue() ? 'TRUE' : 'FALSE',
                    'string' => "'{$property->getDefaultValue()}'",
                    'array' => match ($this->dialect) {
                        'pgsql' => "'" . json_encode($property->getDefaultValue()) . "'::jsonb",
                        'mysql' => "('" . json_encode($property->getDefaultValue()) . "')",
                    },
                    default => "{$property->getDefaultValue()}"
                }
                : null
            )
            : null);

        return new Column(
            name: $property->getName(),
            type: $type,
            nullable: $nullable,
            default: $default,
            indexes: $indexes,
            foreignKey: $foreignKey,
            checkConstraint: $checkConstraint
        );
    }

    private function prepareInstance(
        ReflectionProperty $property,
        ReflectionAttribute $attribute,
        object $instance,
        ?AttributeDbType &$attributeType,
        ?AttributeDbSubType &$attributeTypeSub,
        array &$indexes,
        ?ForeignKey &$foreignKey,
        ?CheckConstraint &$checkConstraint,
        array &$types,
        ?bool &$nullable,
        ?string &$default,
    ): void {
        if ($instance instanceof AttributeDbHybrid) {
            foreach ($instance->getInstances($this->dialect) as $subInstance) {
                $this->prepareInstance(
                    property: $property,
                    attribute: $attribute,
                    instance: $subInstance,
                    attributeType: $attributeType,
                    attributeTypeSub: $attributeTypeSub,
                    indexes: $indexes,
                    foreignKey: $foreignKey,
                    checkConstraint: $checkConstraint,
                    types: $types,
                    nullable: $nullable,
                    default: $default,
                );
            }
        } elseif ($instance instanceof AttributeDbType) {
            if (!$instance->supports($types)) {
                throw new \InvalidArgumentException(
                    $property->getName() . " in " . $property->getDeclaringClass()->getName() . " "
                    . $attribute->getName()
                    . " does not support this type: " . json_encode($types)
                );
            };
            if ($attributeType !== null) {
                throw new \RuntimeException(
                    $property->getName() . " in " . $property->getDeclaringClass()->getName() . " "
                    . $attribute->getName()
                    . " is already set to " . $attributeType::class
                );
            }
            $attributeType = $instance;
        } elseif ($instance instanceof AttributeDbSubType) {
            if (!$instance->supports($types)) {
                throw new \InvalidArgumentException(
                    $property->getName() . " in " . $property->getDeclaringClass()->getName() . " "
                    . $attribute->getName()
                    . " does not support this type: " . json_encode($types)
                );
            };
            $attributeTypeSub = $instance;
        } elseif ($instance instanceof AttributeDbIdx) {
            $instance->columnPreparation($property->getName());
            $indexes[] = $instance->toObject($this->dialect);
        } elseif ($instance instanceof AttributeDbConstraint) {
            if ($instance instanceof AttributeDbConstraintForeign) {
                $foreignKey = $instance->toObject($property->getName(), $this->dialect);
            } elseif ($instance instanceof AttributeDbConstraintCheck) {
                $checkConstraint = $instance->toObject($property->getName(), $this->dialect);
            }
        } elseif ($instance instanceof AttributeDbAdditive) {
            $instance->preparation($nullable, $default);
        }
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    private function checkingType(ReflectionProperty $property): array
    {
        $types = [];
        if ($property->hasType()) {
            $type = $property->getType();

            if ($type instanceof \ReflectionNamedType) {
                $types[] = $type->getName();
                if ($type->allowsNull()) {
                    $types[] = 'null';
                }
            } elseif ($type instanceof \ReflectionUnionType) {
                foreach ($type->getTypes() as $typeSub) {
                    $types[] = $typeSub->getName();
                }
            }
        } else {
            $types[] = 'null';
        }
        return $types;
    }
}
