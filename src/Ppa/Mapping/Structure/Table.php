<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Structure;

class Table implements StructureInterface
{
    /** @var Column[] */
    public array $columns;
    /** @var Index[] */
    public array $indexes;
    /** @var CheckConstraint[] */
    public array $checks;
    /** @var ForeignKey[] */
    public array $foreignKeys;

    public function __construct(
        public string $name,
        array $columns,
        array $indexes = [],
        array $checks = [],
        array $foreignKeys = [],
        public ?string $schema = null
    ) {
        NameValidator::validate($name);
        if ($schema) {
            NameValidator::validate($schema);
        }
        $this->columns = $columns;
        $this->indexes = $indexes;
        $this->checks = $checks;
        $this->foreignKeys = $foreignKeys;
    }

    public function getFullName(): string
    {
        return $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        $tableName = $this->getFullName();
        $columnDefinitions = [];
        $internalStatements = [];
        $externalStatements = [];

        foreach ($this->columns as $column) {
            $columnDefinitions[] = '  ' . $column->toSql($tableName, $dialect);

            foreach ($column->constraintsSql($tableName, $dialect) as $constraint) {
                if (
                    str_starts_with($constraint, 'CREATE ')
                    || str_starts_with($constraint, 'ALTER TABLE')
                ) {
                    $externalStatements[] = $constraint . ';';
                } else {
                    $internalStatements[] = '  ' . $constraint;
                }
            }
        }

        foreach ($this->indexes as $index) {
            $sql = $index->toSql($tableName, $dialect);
            if ($dialect === 'pgsql' && str_starts_with($sql, 'INDEX ')) {
                $externalStatements[] = 'CREATE ' . $sql . ';';
            } else {
                $internalStatements[] = '  ' . $sql;
            }
        }

        foreach ($this->foreignKeys as $foreignKey) {
            $internalStatements[] = '  ' . $foreignKey->toSql($tableName, $foreignKey->columnName, $dialect);
        }

        foreach ($this->checks as $check) {
            $sql = $check->toSql($tableName, $dialect);
            if (
                str_starts_with($sql, 'CREATE ')
                || str_starts_with($sql, 'ALTER TABLE')
            ) {
                $externalStatements[] = $sql . ';';
            } else {
                $internalStatements[] = '  ' . $sql;
            }
        }

        $internalStatements = $this->mergePrimaryKeys($internalStatements);
        $body = implode(",\n", array_merge($columnDefinitions, $internalStatements));
        $tableSql = sprintf("CREATE TABLE %s (\n%s\n);", $tableName, $body);

        if (!empty($externalStatements)) {
            $tableSql .= "\n" . implode("\n", $externalStatements);
        }

        return $tableSql;
    }

    private function mergePrimaryKeys(array $constraints): array
    {
        $primaryColumns = [];
        $otherConstraints = [];

        foreach ($constraints as $line) {
            if (preg_match('/PRIMARY KEY\s*\((.*?)\)/i', $line, $matches)) {
                $columns = array_map('trim', explode(',', $matches[1]));
                $primaryColumns = array_merge($primaryColumns, $columns);
            } else {
                $otherConstraints[] = $line;
            }
        }

        if (!empty($primaryColumns)) {
            $primaryLine = '  PRIMARY KEY (' . implode(', ', array_unique($primaryColumns)) . ')';
            array_unshift($otherConstraints, $primaryLine);
        }

        return $otherConstraints;
    }

    public function createSchemaIfNotExists(string $dialect = 'mysql'): ?string
    {
        if (empty($this->schema)) {
            return null;
        }

        return match ($dialect) {
            'pgsql' => "CREATE SCHEMA {$this->schema};",
            default => null
        };
    }

    public function addColumn(Column $column, string $dialect = 'mysql'): string
    {
        $this->columns[] = $column;
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        return sprintf("ALTER TABLE %s ADD COLUMN %s;", $tableName, $column->toSql($this->name, $dialect));
    }

    public function dropColumn(string $columnName): string
    {
        $this->columns = array_filter($this->columns, fn($col) => $col->name !== $columnName);
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        return sprintf("ALTER TABLE %s DROP COLUMN %s;", $tableName, $columnName);
    }

    public function addIndex(Index $index, string $dialect = 'mysql'): string
    {
        $this->indexes[] = $index;
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        $sql = $index->toSql($this->name, $dialect);
        if ($dialect === 'pgsql' && str_starts_with($sql, 'INDEX ')) {
            return 'CREATE ' . $sql . ';';
        } else {
            return sprintf("ALTER TABLE %s ADD %s;", $tableName, $sql);
        }
    }

    public function dropIndex(string $indexName, string $dialect = 'mysql'): string
    {
        $this->indexes = array_filter($this->indexes, fn($idx) => $idx->name !== $indexName);
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        if ($dialect === 'mysql') {
            return sprintf("ALTER TABLE %s DROP INDEX %s;", $tableName, $indexName);
        } elseif ($dialect === 'pgsql') {
            return sprintf("DROP INDEX %s;", $indexName);
        } else {
            throw new \InvalidArgumentException("Unsupported dialect: {$dialect}");
        }
    }

    public function addForeignKey(ForeignKey $foreignKey, string $dialect = 'mysql'): string
    {
        $this->foreignKeys[] = $foreignKey;
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        return sprintf("ALTER TABLE %s ADD %s;", $tableName, $foreignKey->toSql($this->name, $foreignKey->referencedColumn, $dialect));
    }

    public function dropForeignKey(string $constraintName, string $dialect = 'mysql'): string
    {
        $this->foreignKeys = array_filter($this->foreignKeys, fn($fk) => $fk->name !== $constraintName); // Assuming ForeignKey has a name property
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        if ($dialect === 'mysql') {
            return sprintf("ALTER TABLE %s DROP FOREIGN KEY %s;", $tableName, $constraintName);
        } elseif ($dialect === 'pgsql') {
            return sprintf("ALTER TABLE %s DROP CONSTRAINT %s;", $tableName, $constraintName);
        } else {
            throw new \InvalidArgumentException("Unsupported dialect: {$dialect}");
        }
    }

    public function addCheckConstraint(CheckConstraint $check, string $dialect = 'mysql'): string
    {
        $this->checks[] = $check;
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        return sprintf("ALTER TABLE %s ADD %s;", $tableName, $check->toSql($this->name, $dialect));
    }

    public function dropCheckConstraint(string $checkName, string $dialect = 'mysql'): string
    {
        $this->checks = array_filter($this->checks, fn($chk) => $chk->name !== $checkName);
        $tableName = $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
        if ($dialect === 'mysql' || $dialect === 'pgsql') {
            return sprintf("ALTER TABLE %s DROP CONSTRAINT %s;", $tableName, $checkName);
        } else {
            throw new \InvalidArgumentException("Unsupported dialect: {$dialect}");
        }
    }
}
