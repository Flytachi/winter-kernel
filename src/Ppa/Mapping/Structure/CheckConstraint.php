<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Structure;

class CheckConstraint implements StructureInterface
{
    public function __construct(
        public string $expression,
        public ?string $name = null,
    ) {
        if ($name) {
            NameValidator::validate($name);
        }
    }

    public function toSql(string $tableName, string $dialect = 'mysql'): string
    {
        if ($this->name) {
            $constraintName = $this->name;
        } else {
            $expName = explode('.', $tableName);
            $constraintName = count($expName) > 1 ? "chk_" . $expName[1] . "_" : "chk_{$tableName}_";
            $constraintName .= md5($this->expression);
        }
        return "ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} CHECK ({$this->expression})";
    }
}
