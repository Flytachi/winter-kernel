<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Additive;

use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDb;

interface AttributeDbAdditive extends AttributeDb
{
    public function preparation(?bool &$nullable, ?string &$default): void;
}
