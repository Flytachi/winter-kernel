<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Structure;

class Trigger implements StructureInterface
{
    public function __construct(
        public string $name,
        public string $tableName,
        public string $event,
        public string $timing,
        public string $definition,
        public string $granularity = 'ROW' // ROW or STATEMENT
    ) {
        NameValidator::validate($name);
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        if ($dialect === 'mysql') {
            return sprintf(
                "CREATE TRIGGER %s %s %s ON %s FOR EACH %s %s;",
                $this->name,
                $this->timing,
                $this->event,
                $this->tableName,
                $this->granularity,
                $this->definition
            );
        } elseif ($dialect === 'pgsql') {
            return sprintf(
                "CREATE TRIGGER %s %s %s ON %s FOR EACH %s EXECUTE FUNCTION %s();",
                $this->name,
                $this->timing,
                $this->event,
                $this->tableName,
                $this->granularity,
                $this->definition // In PostgreSQL, definition is usually a function name
            );
        } else {
            throw new \InvalidArgumentException("Unsupported dialect for Trigger: {$dialect}");
        }
    }
}
