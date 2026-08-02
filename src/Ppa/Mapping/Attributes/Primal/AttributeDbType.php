<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal;

use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDb;

interface AttributeDbType extends AttributeDb
{
    public function supports(array $phpTypes): bool;
    public function toSql(string $dialect = 'mysql'): string;
}
