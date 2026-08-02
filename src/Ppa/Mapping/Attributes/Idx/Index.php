<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Idx;

use Attribute;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexType;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Index implements AttributeDbIdx
{
    public function __construct(
        private array $columns = [],
        private readonly ?string $name = null,
        public IndexMethod $method = IndexMethod::BTREE,
        private readonly ?string $where = null,
        private readonly ?string $opClass = null
    ) {
    }

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
            name: $this->name,
            type: IndexType::INDEX,
            method: $this->method,
            where: $this->where,
            opClass: $this->opClass
        );
    }
}
