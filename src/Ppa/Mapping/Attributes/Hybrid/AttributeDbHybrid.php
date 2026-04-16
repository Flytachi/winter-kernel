<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\AttributeDbAdditive;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\AttributeDbIdx;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\AttributeDbType;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Sub\AttributeDbSubType;

interface AttributeDbHybrid extends AttributeDb
{
    /**
     * @param string $dialect
     * @return array<AttributeDbType|AttributeDbSubType|AttributeDbIdx|AttributeDbAdditive>
     */
    public function getInstances(string $dialect = 'mysql'): array;
}
