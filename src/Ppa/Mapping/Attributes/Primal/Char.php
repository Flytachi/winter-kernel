<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Char implements AttributeDbType
{
    /**
     * Defines a fixed-length character string column.
     *
     * @param int $length The exact length of the CHAR string. This parameter is mandatory.
     */
    public function __construct(
        private int $length,
    ) {
        if ($this->length < 1) {
            throw new InvalidArgumentException(
                'The length for a CHAR type must be at least 1.'
            );
        }
    }

    public function supports(array $phpTypes): bool
    {
        return in_array('string', $phpTypes);
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        return "CHAR({$this->length})";
    }
}
