<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Structure;

class StoredProcedure implements StructureInterface
{
    public function __construct(
        public string $name,
        public string $definition,
    ) {
        NameValidator::validate($name);
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        // Stored procedures SQL generation is highly dialect-specific and complex.
        // This is a simplified example.
        if ($dialect === 'mysql') {
            return sprintf(
                "DELIMITER //\nCREATE PROCEDURE %s()\nBEGIN\n%s\nEND //\nDELIMITER ;",
                $this->name,
                $this->definition
            );
        } elseif ($dialect === 'pgsql') {
            return sprintf(
                "CREATE OR REPLACE FUNCTION %s() RETURNS VOID AS $$\nBEGIN\n%s\nEND;\n$$ LANGUAGE plpgsql;",
                $this->name,
                $this->definition
            );
        } else {
            throw new \InvalidArgumentException("Unsupported dialect for Stored Procedure: {$dialect}");
        }
    }
}
