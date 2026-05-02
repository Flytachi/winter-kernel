<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the numeric value is negative (< 0). Zero is allowed.
 * Supports int, float, BcMath\Number, Decimal\Decimal.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[PositiveOrZero] int   $stock   // -1 → fail, 0 → pass, 5 → pass
 *   #[PositiveOrZero] float $balance // -0.01 → fail, 0.0 → pass
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class PositiveOrZero implements Constraint
{
    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $n = self::toFloat($value);
        if ($n === null) {
            return null;
        }
        return $n >= 0.0 ? null : 'must be positive or zero';
    }

    private static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (float) (string) $value;
        }
        return null;
    }
}
