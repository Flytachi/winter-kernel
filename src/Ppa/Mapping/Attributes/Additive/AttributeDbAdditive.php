<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDb;

interface AttributeDbAdditive extends AttributeDb
{
    public function preparation(?bool &$nullable, ?string &$default): void;
}
