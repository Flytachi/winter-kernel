<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Additive;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class DefaultVal implements AttributeDbAdditive
{
    public function __construct(
        private string $definition,
    ) {
    }

    public function preparation(?bool &$nullable, ?string &$default): void
    {
        $default = $this->definition;
    }
}
