<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Decimal extends FloatType implements AttributeDbType
{
    /**
     * @param int $precision The total number of digits that can be stored
     * both to the left and to the right of the decimal point.
     * @param int $scale The number of digits to the right of the decimal point.
     */
    public function __construct(
        private int $precision = 12,
        private int $scale = 2
    ) {
    }

    final public function supports(array $phpTypes): bool
    {
        $phpTypes = array_filter($phpTypes, fn($type) => $type !== 'null');

        if (
            count($phpTypes) === 1
            && in_array($phpTypes[0], [
                'mixed', 'int', 'float', 'string',
                'Decimal\Decimal', 'BcMath\Number',
            ])
        ) {
            return true;
        } elseif (
            count($phpTypes) > 1
        ) {
            $array = array_diff($phpTypes, ['int', 'float']);
            return empty($array);
        }
        return false;
    }

    public function toSql(string $dialect = 'mysql'): string
    {
        return match ($dialect) {
            'mysql' => "DECIMAL({$this->precision}, {$this->scale})",
            'pgsql' => "NUMERIC({$this->precision}, {$this->scale})",
        };
    }
}
