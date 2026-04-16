<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx;

use Attribute;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexType;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Primary implements AttributeDbIdx
{
    private array $columns = [];

    public function columnPreparation(string $columnMain): void
    {
        if (!in_array($columnMain, $this->columns)) {
            array_unshift($this->columns, $columnMain);
        }
    }

    public function toObject(string $dialect = 'mysql'): \Flytachi\Winter\K2\Ppa\Mapping\Structure\Index
    {
        return new \Flytachi\Winter\K2\Ppa\Mapping\Structure\Index(
            columns: $this->columns,
            type: IndexType::PRIMARY,
            method: IndexMethod::BTREE,
        );
    }
}
