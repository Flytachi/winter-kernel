<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid;

use Attribute;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Primary;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\UuidBase;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Uuid implements AttributeDbHybrid
{
    public function getInstances(string $dialect = 'mysql'): array
    {
        return [
            new Primary(),
            new UuidBase(),
            new NullableIs(false),
            new DefaultVal($dialect === 'pgsql'
                ? "gen_random_uuid()"
                : "UUID()")
        ];
    }
}
