<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Type implements AttributeDbType
{
    /**
     * @param string $definition The SQL type definition string (e.g., 'VARCHAR(255)', 'INT', 'TEXT').
     */
    public function __construct(
        private string $definition,
    ) {
    }

    public function supports(array $phpTypes): bool
    {
        return true;
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        return $this->definition;
    }
}
