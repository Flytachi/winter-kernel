<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Binary implements AttributeDbType
{
    public function __construct(
        private int $length = 255
    ) {
        if ($this->length <= 0) {
            throw new InvalidArgumentException('Binary length must be a positive integer.');
        }
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
            'pgsql' => "BYTEA",
            'sqlite' => "BLOB",
            default => "VARBINARY({$this->length})",
        };
    }
}
