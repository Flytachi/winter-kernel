<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint;

use Flytachi\Winter\K2\Ppa\Mapping\Structure\CheckConstraint;

interface AttributeDbConstraintCheck extends AttributeDbConstraint
{
    public function toObject(string $columnName, string $dialect = 'mysql'): CheckConstraint;
}
