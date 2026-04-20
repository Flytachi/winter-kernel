<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\DataTableNet;

class DataTableNetResponse
{
    public function __construct(
        public readonly int $draw,
        public readonly int $recordsTotal,
        public readonly int $recordsFiltered,
        public readonly array $data
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'draw'            => $this->draw,
            'recordsTotal'    => $this->recordsTotal,
            'recordsFiltered' => $this->recordsFiltered,
            'data'            => $this->data,
        ];
    }
}
