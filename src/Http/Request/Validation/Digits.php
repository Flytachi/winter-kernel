<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Validates the digit structure of a numeric value.
 * Checks max digits before the decimal point (integer part)
 * and max digits after it (fraction part).
 * Supports int, float, string, BcMath\Number, Decimal\Decimal.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Digits(integer: 6, fraction: 2)]
 *   // 9999.99   → pass  (≤6 integer, ≤2 fraction)
 *   // 1234567.0 → fail  (7 integer digits)
 *   // 9.999     → fail  (3 fraction digits)
 *   // -99.50    → pass  (sign ignored)
 * ```
 *
 * @link https://winterframe.net/docs/validation Constraints, error shape and messages
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Digits implements Constraint
{
    /**
     * @param int $integer Max allowed digits in the integer part (before decimal point).
     * @param int $fraction Max allowed digits in the fraction part (after decimal point). Defaults to 0.
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(
        public int $integer,
        public int $fraction = 0,
        public ?string $message = null,
    ) {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = match (true) {
            is_int($value) || is_float($value) => (string) $value,
            is_string($value) && is_numeric($value) => $value,
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            default => null,
        };

        if ($str === null) {
            return null;
        }

        $str      = ltrim($str, '-+');
        $parts    = explode('.', $str, 2);
        $intPart  = ltrim($parts[0], '0') ?: '0';
        $fracPart = rtrim($parts[1] ?? '', '0');

        if (strlen($intPart) > $this->integer) {
            return $this->message ?? "integer part must not exceed {$this->integer} digits";
        }
        if (strlen($fracPart) > $this->fraction) {
            return $this->message ?? "fraction part must not exceed {$this->fraction} digits";
        }
        return null;
    }
}
