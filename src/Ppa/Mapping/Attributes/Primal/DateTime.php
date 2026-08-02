<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class DateTime implements AttributeDbType
{
    final public function supports(array $phpTypes): bool
    {
        $phpTypes = array_filter($phpTypes, fn($type) => $type !== 'null');
        if (
            count($phpTypes) === 1
            && in_array($phpTypes[0], ['mixed', 'string', '\DateTimeImmutable', '\DateTime'])
        ) {
            return true;
        } elseif (
            count($phpTypes) > 1
        ) {
            $array = array_diff($phpTypes, ['string', '\DateTimeImmutable', '\DateTime']);
            return empty($array);
        }
        return false;
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        return match ($dialect) {
            'pgsql' => "TIMESTAMP WITHOUT TIME ZONE",
            default => "DATETIME",
        };
    }
}
