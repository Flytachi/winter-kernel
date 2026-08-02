<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Fails if the numeric value is not strictly negative (< 0).
 * Supports int, float, BcMath\Number, Decimal\Decimal.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Negative] int   $delta   // 0 → fail, 1 → fail, -1 → pass
 *   #[Negative] float $offset  // 0.0 → fail, -0.01 → pass
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Negative implements Constraint
{
    /**
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(public ?string $message = null)
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
        return $n < 0.0 ? null : ($this->message ?? 'must be negative');
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
