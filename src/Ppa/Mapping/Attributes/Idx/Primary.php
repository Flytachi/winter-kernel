<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Idx;

use Attribute;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexType;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Primary implements AttributeDbIdx
{
    private array $columns = [];

    public function columnPreparation(string $columnMain): void
    {
        if (!in_array($columnMain, $this->columns)) {
            array_unshift($this->columns, $columnMain);
        }
    }

    public function toObject(string $dialect = 'mysql'): \Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Index
    {
        return new \Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Index(
            columns: $this->columns,
            type: IndexType::PRIMARY,
            method: IndexMethod::BTREE,
        );
    }
}
