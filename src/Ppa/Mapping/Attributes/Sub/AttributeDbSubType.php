<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Sub;

use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDb;

interface AttributeDbSubType extends AttributeDb
{
    public function supports(array &$phpTypes): bool;
    public function toSql(string $type, string $dialect = 'mysql'): string;
}
