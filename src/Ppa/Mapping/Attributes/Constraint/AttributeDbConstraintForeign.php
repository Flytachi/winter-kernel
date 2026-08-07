<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Constraint;

use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\ForeignKey;

interface AttributeDbConstraintForeign extends AttributeDbConstraint
{
    public function toObject(string $columnName, string $dialect = 'mysql'): ForeignKey;
}
