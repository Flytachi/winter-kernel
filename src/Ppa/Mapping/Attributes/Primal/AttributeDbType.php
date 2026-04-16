<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDb;

interface AttributeDbType extends AttributeDb
{
    public function supports(array $phpTypes): bool;
    public function toSql(string $dialect = 'mysql'): string;
}
