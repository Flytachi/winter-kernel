<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the numeric value exceeds the given maximum.
 * Supports int, float, BcMath\Number, Decimal\Decimal.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Max(100)]   int    $percent   // 101 → fail, 100 → pass
 *   #[Max(999.99)] float $price     // 1000.0 → fail, 999.99 → pass
 *   #[Max(1000)]  Number $amount    // BcMath\Number("1001") → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Max implements Constraint
{
    /**
     * @param int|float $value Upper bound (inclusive). Value must be ≤ this.
     */
    public function __construct(public int|float $value)
    {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $n = self::toFloat($value);
        if ($n === null) {
            return null;
        }
        return $n <= (float) $this->value ? null : "must not exceed {$this->value}";
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
