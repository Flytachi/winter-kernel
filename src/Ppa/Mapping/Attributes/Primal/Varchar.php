<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Varchar implements AttributeDbType
{
    /**
     * @param int $length The maximum length of the VARCHAR string. Defaults to 255.
     */
    public function __construct(
        private int $length = 255,
    ) {
    }

    public function supports(array $phpTypes): bool
    {
        return true;
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        return 'VARCHAR' . ($this->length > 0 ? "({$this->length})" : '');
    }
}
