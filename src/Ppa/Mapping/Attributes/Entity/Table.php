<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity;

use Attribute;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDbEntity;

#[Attribute(Attribute::TARGET_CLASS)]
final class Table implements AttributeDbEntity
{
}
