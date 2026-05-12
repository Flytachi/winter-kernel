<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Validates the size of a value depending on its type:
 * ```
 *   string              → mb_strlen  (character count)
 *   array               → count      (element count)
 *   int, float, Number  → strlen((string) $v)  e.g. 100 → 3, -5 → 2
 * ```
 *
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * Constructor (first positional = max for the common "up to N" shorthand):
 * ```
 *   #[Size(10)]              // max=10, min=0  — up to 10
 *   #[Size(min: 2)]          // min=2, max=∞   — at least 2
 *   #[Size(min: 2, max: 255)] // range 2..255
 *   #[Size(3, message: 'Имя не длиннее 3 символов')] // custom error text
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Size implements Constraint
{
    /**
     * @param int $max Upper bound (inclusive). First positional arg — #[Size(10)] means max=10.
     * @param int $min Lower bound (inclusive). Defaults to 0 (no lower bound).
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(
        public int $max = PHP_INT_MAX,
        public int $min = 0,
        public ?string $message = null,
    ) {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $size = match (true) {
            is_string($value)  => mb_strlen($value),
            is_array($value)   => count($value),
            is_int($value) || is_float($value) => mb_strlen((string) $value),
            is_object($value) && method_exists($value, '__toString') => mb_strlen((string) $value),
            default            => null,
        };

        if ($size === null) {
            return null;
        }

        if ($size >= $this->min && $size <= $this->max) {
            return null;
        }

        if ($this->message !== null) {
            return $this->message;
        }
        if ($this->min === 0) {
            return "must not exceed {$this->max}";
        }
        if ($this->max === PHP_INT_MAX) {
            return "must be at least {$this->min}";
        }
        return "size must be between {$this->min} and {$this->max}";
    }
}
