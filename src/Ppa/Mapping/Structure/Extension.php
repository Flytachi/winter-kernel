<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Structure;

/**
 * SQL emitter for a PostgreSQL extension declaration.
 *
 * Built from {@see \Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Extension}
 * attributes during {@see \Flytachi\Winter\K2\Ppa\DeclarationItem} construction.
 */
final class Extension implements StructureInterface
{
    public function __construct(
        public string $name,
        public ?string $version = null,
        public ?string $schema = null,
        public bool $cascade = false,
    ) {
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        if ($dialect !== 'pgsql') {
            throw new \InvalidArgumentException("Unsupported dialect: {$dialect}");
        }

        $sql = "CREATE EXTENSION IF NOT EXISTS \"{$this->name}\"";
        if ($this->schema !== null) {
            $sql .= " WITH SCHEMA {$this->schema}";
        }
        if ($this->version !== null) {
            $sql .= " VERSION '{$this->version}'";
        }
        if ($this->cascade) {
            $sql .= ' CASCADE';
        }
        return $sql . ';';
    }
}
