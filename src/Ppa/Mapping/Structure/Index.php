<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Structure;

use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexType;

class Index implements StructureInterface
{
    public function __construct(
        public array $columns,
        public ?string $name = null,
        public IndexType $type = IndexType::INDEX,
        public IndexMethod $method = IndexMethod::BTREE,
        public ?string $where = null,
        public array $includeColumns = [], // New property for PostgreSQL INCLUDE clause
        public ?string $opClass = null // TODO нужно будет добавить create extention (#[Extension(name: ?'pg_trgm')])
    ) {
        if ($name) {
            NameValidator::validate($name);
        }
    }

    public function toSql(string $tableName, string $dialect = 'mysql'): string
    {
        $cols = array_values($this->columns);
        if ($this->opClass !== null) {
            $cols[0] .= ' ' . $this->opClass;
        }
        $columnsSql = ' (' . implode(', ', $cols) . ')';

        $baseName = $this->name;
        if (!$baseName) {
            $cleanColumns = array_map(fn($col) => preg_replace('/[^a-zA-Z0-9_]/', '', $col), $this->columns);
            $baseName = implode('_', $cleanColumns);
        }

        $cleanTableName = str_replace(['"', '`'], '', basename(str_replace('.', '/', $tableName)));

        $suffix = match ($this->type) {
            IndexType::PRIMARY => '_pkey',
            IndexType::UNIQUE => '_udx',
            IndexType::INDEX => '_idx',
        };
        $nameSql = "{$cleanTableName}_{$baseName}{$suffix}";

        $limit = $dialect === 'pgsql' ? 63 : 64;
        if (strlen($nameSql) > $limit) {
            // crc32b returns 8 hex chars; reserve 8 + 1 (underscore) so the
            // truncated name fits exactly inside the identifier length limit.
            $suffix = '_' . hash('crc32b', $nameSql);
            $nameSql = substr($nameSql, 0, $limit - strlen($suffix)) . $suffix;
        }

        if ($dialect === 'mysql') {
            $methodSql = $this->method !== IndexMethod::BTREE ? " USING {$this->method->value}" : '';

            return match ($this->type) {
                IndexType::PRIMARY => "PRIMARY KEY" . $columnsSql,
                IndexType::UNIQUE => "CREATE UNIQUE INDEX {$nameSql} ON {$tableName}{$methodSql}{$columnsSql}",
                IndexType::INDEX => "CREATE INDEX {$nameSql} ON {$tableName}{$methodSql}{$columnsSql}",
            };
        }

        if ($dialect === 'pgsql') {
            $usingSql = " USING {$this->method->value}"; // В PG принято всегда указывать метод
            $whereSql = $this->where ? " WHERE {$this->where}" : '';
            $includeSql = !empty($this->includeColumns)
                ? ' INCLUDE (' . implode(', ', $this->includeColumns) . ')'
                : '';

            return match ($this->type) {
                IndexType::PRIMARY => "PRIMARY KEY" . $columnsSql,
                IndexType::UNIQUE => "CREATE UNIQUE INDEX {$nameSql} ON {$tableName}{$usingSql}"
                    . "{$columnsSql}{$includeSql}{$whereSql}",
                IndexType::INDEX => "CREATE INDEX {$nameSql} ON {$tableName}{$usingSql}"
                    . "{$columnsSql}{$includeSql}{$whereSql}",
            };
        }

        throw new \InvalidArgumentException("Unsupported dialect: {$dialect}");
    }
}
