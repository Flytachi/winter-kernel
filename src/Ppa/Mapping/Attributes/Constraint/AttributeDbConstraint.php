<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\StructureInterface;

interface AttributeDbConstraint extends AttributeDb
{
    public function toObject(string $columnName, string $dialect = 'mysql'): StructureInterface;
}
