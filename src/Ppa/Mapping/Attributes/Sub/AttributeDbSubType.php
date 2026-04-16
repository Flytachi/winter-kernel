<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Sub;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDb;

interface AttributeDbSubType extends AttributeDb
{
    public function supports(array &$phpTypes): bool;
    public function toSql(string $type, string $dialect = 'mysql'): string;
}
