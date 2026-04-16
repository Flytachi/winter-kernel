<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class NullableIs implements AttributeDbAdditive
{
    public function __construct(
        private bool $isNullable = true,
    ) {
    }

    public function preparation(?bool &$nullable, ?string &$default): void
    {
        $nullable = $this->isNullable;
    }
}
