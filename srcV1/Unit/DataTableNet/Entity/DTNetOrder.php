<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\DataTableNet\Entity;

class DTNetOrder
{
    public function __construct(
        public int $column,
        public string $dir = 'asc',
    ) {
    }
}
