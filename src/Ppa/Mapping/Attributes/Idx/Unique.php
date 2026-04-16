<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx;

use Attribute;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexType;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Unique implements AttributeDbIdx
{
    public function __construct(
        private array $columns = [],
        private readonly ?string $name = null,
        public IndexMethod $method = IndexMethod::BTREE,
    ) {
    }

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
            name: $this->name,
            type: IndexType::UNIQUE,
            method: IndexMethod::BTREE,
        );
    }
}
