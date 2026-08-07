<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Uuid implements AttributeDbType
{
    public function __construct(
        private bool $asBinary = false
    ) {
    }

    public function supports(array $phpTypes): bool
    {
        $phpTypes = array_filter($phpTypes, fn($type) => $type !== 'null');
        if (
            count($phpTypes) === 1
            && in_array($phpTypes[0], ['mixed', 'string'])
        ) {
            return true;
        }
        return false;
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        return match ($dialect) {
            'mysql' => $this->asBinary ? "BINARY(16)" : "CHAR(36)",
            'pgsql' => "UUID",
            'sqlite' => "TEXT",
            default => "CHAR(36)",
        };
    }
}
