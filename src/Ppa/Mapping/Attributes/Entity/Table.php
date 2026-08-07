<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Entity;

use Attribute;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDbEntity;

#[Attribute(Attribute::TARGET_CLASS)]
final class Table implements AttributeDbEntity
{
}
