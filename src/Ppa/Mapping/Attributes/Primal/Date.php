<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Date extends DateTime implements AttributeDbType
{
    public function toSql(string $dialect = 'mysql'): string
    {
        return 'DATE';
    }
}
