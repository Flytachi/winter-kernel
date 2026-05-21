<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use JsonSerializable;

final readonly class PaginationResult implements JsonSerializable
{
    /**
     * @param PaginationMeta|PaginationMetaCursor $meta
     * @param array $data
     */
    public function __construct(
        public PaginationMeta|PaginationMetaCursor $meta,
        public array $data
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'meta' => $this->meta,
            'data' => $this->data,
        ];
    }
}