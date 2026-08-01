<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Double extends FloatType implements AttributeDbType
{
    public function toSql(string $dialect = 'mysql'): string
    {
        return match ($dialect) {
            'pgsql' => "DOUBLE PRECISION",
            // Every SQLite float is an 8-byte IEEE double; REAL is the only spelling.
            'sqlite' => "REAL",
            default => "DOUBLE",
        };
    }
}
