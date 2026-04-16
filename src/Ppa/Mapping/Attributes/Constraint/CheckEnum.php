<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint;

use Attribute;
use BackedEnum;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\CheckConstraint;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class CheckEnum implements AttributeDbConstraintCheck
{
    /**
     * @param class-string<BackedEnum> $enumClassName
     * @param string|null $name
     */
    public function __construct(
        public string $enumClassName,
        public ?string $name = null
    ) {
        if (!enum_exists($this->enumClassName)) {
            throw new InvalidArgumentException(sprintf('Class "%s" is not a valid enum.', $this->enumClassName));
        }
        if (!is_subclass_of($this->enumClassName, BackedEnum::class)) {
            throw new InvalidArgumentException(
                sprintf('Enum class "%s" must be a Backed Enum (string or int).', $this->enumClassName)
            );
        }
    }

    public function toObject(string $columnName, string $dialect = 'mysql'): CheckConstraint
    {
        $values = $this->enumClassName::cases();
        if (is_int($values[0]->value)) {
            $values = array_map(
                fn(BackedEnum $case) => $case->value,
                $values
            );
        } else {
            $values = array_map(
                fn(BackedEnum $case) => "'" . addslashes((string)$case->value) . "'",
                $values
            );
        }
        $values = implode(', ', $values);

        return new CheckConstraint(
            expression: "{$columnName} IN ({$values})"
        );
    }
}
