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
 * Two modes only — exact or range:
 * ```
 *   #[Size(3)]      // exact 3
 *   #[Size(2, 255)] // range 2..255 (inclusive)
 *   #[Size(3, message: 'Имя должно быть длиной 3 символа')] // custom error text
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Size implements Constraint
{
    /**
     * @param int $min Required. Lower bound (inclusive). When `$max` is omitted, also acts as the exact required size.
     * @param int $max Upper bound (inclusive). Defaults to 0, which means "use `$min`" (exact mode).
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(
        public int $min,
        public int $max = 0,
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

        $max = $this->max === 0 ? $this->min : $this->max;

        if ($size >= $this->min && $size <= $max) {
            return null;
        }

        if ($this->message !== null) {
            return $this->message;
        }
        if ($this->min === $max) {
            return "must be exactly {$this->min}";
        }
        return "size must be between {$this->min} and {$max}";
    }
}
