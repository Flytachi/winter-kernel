<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use JsonSerializable;

final readonly class PaginationMetaCursor implements JsonSerializable
{
    public function __construct(
        public int $size,
        public ?string $beforeCursor,
        public ?string $afterCursor,
        public bool $hasNextPage,
        public bool $hasPrevPage,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'size'              => $this->size,
            'has_next_page'     => $this->hasNextPage,
            'has_previous_page' => $this->hasPrevPage,
            'before_cursor'     => $this->beforeCursor,
            'after_cursor'      => $this->afterCursor,
        ];
    }
}