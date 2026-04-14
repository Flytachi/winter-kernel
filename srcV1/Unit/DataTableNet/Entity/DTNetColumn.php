<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\DataTableNet\Entity;

class DTNetColumn
{
    public function __construct(
        public string $data = '',
        public string $name = '',
        public bool $searchable = false,
        public bool $orderable = false,
        public DTNetSearch $search = new DTNetSearch()
    ) {
    }

    public static function fromArray(array $input): self
    {
        return new self(
            data: $input['data'] ?? '',
            name: $input['name'] ?? '',
            searchable: (bool)($input['searchable'] ?? false),
            orderable: (bool)($input['orderable'] ?? false),
            search: new DTNetSearch(
                value: $input['search']['value'] ?? '',
                regex: $input['search']['regex'] ?? false
            )
        );
    }
}
