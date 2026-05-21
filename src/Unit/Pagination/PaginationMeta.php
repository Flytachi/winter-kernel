<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use JsonSerializable;

final readonly class PaginationMeta implements JsonSerializable
{
    public function __construct(
        public int $page,
        public int $size,
        public int $total
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'page'  => $this->page,
            'size'  => $this->size,
            'total' => $this->total,
        ];
    }
}