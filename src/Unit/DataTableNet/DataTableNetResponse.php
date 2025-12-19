<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\DataTableNet;

class DataTableNetResponse
{
    public int $draw;
    public int $recordsTotal;
    public int $recordsFiltered;
    /** @var array */
    public array $data;

    public function __construct(
        int $draw,
        int $recordsTotal,
        int $recordsFiltered,
        array $data
    ) {
        $this->draw = $draw;
        $this->recordsTotal = $recordsTotal;
        $this->recordsFiltered = $recordsFiltered;
        $this->data = $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw' => $this->draw,
            'recordsTotal' => $this->recordsTotal,
            'recordsFiltered' => $this->recordsFiltered,
            'data' => $this->data,
        ];
    }
}
