<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Structure;

final class Column implements StructureInterface
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = true,
        public ?string $default = null,
        /** @var Index[] */
        public array $indexes = [],
        public ?ForeignKey $foreignKey = null,
        public ?CheckConstraint $checkConstraint = null,
    ) {
        NameValidator::validate($name);
    }

    public function toSql(string $tableName, string $dialect = 'mysql'): string
    {
        $parts = [
            $this->name,
            $this->type,
            $this->nullable ? '' : 'NOT NULL',
        ];

        if ($this->default !== null) {
            $parts[] = 'DEFAULT ' . $this->default;
        }

        return implode(' ', array_filter($parts));
    }


    public function constraintsSql(string $tableName, string $dialect = 'mysql'): array
    {
        $result = [];

        foreach ($this->indexes as $index) {
            $result[] = $index->toSql($tableName, $dialect);
        }

        if ($this->foreignKey) {
            $result[] = $this->foreignKey->toSql($tableName, $this->name, $dialect);
        }

        if ($this->checkConstraint) {
            $result[] = $this->checkConstraint->toSql($tableName, $dialect);
        }

        return $result;
    }

    public static function getPrimitiveSqlType(array $types, string $dialect = 'mysql'): string
    {
        $types = array_filter($types, fn($type) => $type !== 'null');

        // Normalize types to lowercase for easier comparison
        $types = array_map('strtolower', $types);
        sort($types);

        if (count($types) == 1) {
            return match ($types[0]) {
                'bool' => 'BOOLEAN',
                'int' => 'INT',
                'float' => match ($dialect) {
                    'mysql' => 'FLOAT',
                    'pgsql' => 'REAL',
                    default => 'TEXT', // Fallback for unknown dialects
                },
                'string' => 'VARCHAR(255)',
                default => 'TEXT',
            };
        } elseif (count($types) > 1) {
            // Handle combined types
            if (in_array('float', $types) && in_array('int', $types)) {
                return match ($dialect) {
                    'mysql' => 'DOUBLE',
                    'pgsql' => 'DOUBLE PRECISION',
                    default => 'TEXT',
                };
            }

            // int|string -> TEXT or VARCHAR, depending on expected length
            // For simplicity, let's map to TEXT for now, as it's a common fallback for mixed types
            if (in_array('int', $types) && in_array('string', $types)) {
                return 'TEXT';
            }

            // bool|int -> INT (0 or 1 for bool, plus int range)
            if (in_array('bool', $types) && in_array('int', $types)) {
                return 'INT';
            }

            // Fallback for any other unhandled combinations
            return 'TEXT';
        }

        return 'TEXT'; // Default for empty or unhandled cases
    }
}
