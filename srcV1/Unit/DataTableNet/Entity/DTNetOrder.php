<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\DataTableNet\Entity;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
class DTNetOrder
{
    public function __construct(
        public int $column,
        public string $dir = 'asc',
    ) {
    }
}
