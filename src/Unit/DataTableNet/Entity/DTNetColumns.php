<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\DataTableNet\Entity;

class DTNetColumns
{
    /** @var DTNetColumn[] */
    public array $items = [];

    public function __construct(array $items = [])
    {
        foreach ($items as $column) {
            if (is_array($column)) {
                $this->items[] = DTNetColumn::fromArray($column);
            }
        }
    }
}
