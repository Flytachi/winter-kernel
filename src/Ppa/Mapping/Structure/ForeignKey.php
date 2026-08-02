<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\FKAction;

final class ForeignKey implements StructureInterface
{
    public function __construct(
        public string $referencedTable,
        public string $referencedColumn,
        public FKAction $onUpdate = FKAction::RESTRICT,
        public FKAction $onDelete = FKAction::RESTRICT,
        public ?string $name = null,
        /** Local column the FK is attached to. Required when the FK is rendered via Table-level $foreignKeys. */
        public ?string $columnName = null,
    ) {
        if ($name) {
            NameValidator::validate($name);
        }
    }

    public function toSql(string $tableName, string $columnName, string $dialect = 'mysql'): string
    {
        if ($this->name) {
            $constraintName = $this->name;
        } else {
            $expName = explode('.', $tableName);
            $constraintName = (count($expName) > 1 ? "fk_" . $expName[1] : "fk_{$tableName}")
                . "_{$columnName}";
        }
        $onDelete = "ON DELETE " . $this->onDelete->value;
        $onUpdate = "ON UPDATE " . $this->onUpdate->value;

        return "ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} FOREIGN KEY ({$columnName})"
            . " REFERENCES {$this->referencedTable}({$this->referencedColumn}) {$onDelete} {$onUpdate}";
    }
}
