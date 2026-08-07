<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Text implements AttributeDbType
{
    public function supports(array $phpTypes): bool
    {
        return true;
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        return 'TEXT';
    }
}
