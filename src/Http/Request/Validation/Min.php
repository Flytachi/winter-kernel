<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Fails if the numeric value is less than the given minimum.
 * Supports int, float, BcMath\Number, Decimal\Decimal.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Min(1)]    int    $quantity   // 0 → fail, 1 → pass
 *   #[Min(0.01)] float  $price      // 0.0 → fail, 0.01 → pass
 *   #[Min(1)]    Number $amount     // BcMath\Number("0") → fail
 * ```
 *
 * @link https://winterframe.net/docs/validation Constraints, error shape and messages
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Min implements Constraint
{
    /**
     * @param int|float $value Lower bound (inclusive). Value must be ≥ this.
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(
        public int|float $value,
        public ?string $message = null,
    ) {
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
        return $n >= (float) $this->value
            ? null
            : ($this->message ?? "must be at least {$this->value}");
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
