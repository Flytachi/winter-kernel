<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint;

use Attribute;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\CheckConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
readonly class Check implements AttributeDbConstraintCheck
{
    public function __construct(
        public string $expression,
        public ?string $name = null
    ) {
    }

    public function toObject(string $columnName, string $dialect = 'mysql'): CheckConstraint
    {
        return new CheckConstraint(
            expression: $this->expression,
            name: $this->name
        );
    }
}
