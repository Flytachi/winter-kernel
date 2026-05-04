<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the string value does not match the given regular expression.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * Pattern must be a full PHP regex with delimiters.
 * An optional message overrides the default error text.
 *
 * ```
 *   #[Regex('/^\d{4}$/')] string $year         // "2024" → pass, "abc" → fail
 *   #[Regex('/^[a-z]+$/', 'only lowercase')]    // custom message
 *   #[Regex('/^\+?\d{7,15}$/')] string $phone
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Regex implements Constraint
{
    /**
     * @param string      $pattern Full PHP regex with delimiters, e.g. '/^\d{4}$/'.
     * @param string|null $message Custom error message. Defaults to "must match pattern {$pattern}".
     */
    public function __construct(
        public string $pattern,
        public ?string $message = null,
    ) {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return preg_match($this->pattern, (string) $value) === 1
            ? null
            : ($this->message ?? "must match pattern {$this->pattern}");
    }
}
