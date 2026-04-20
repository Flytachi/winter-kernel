<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\DataTableNet\Entity;

class DTNetSearch
{
    public function __construct(
        public string $value = '',
        public bool $regex = false
    ) {
    }
}
