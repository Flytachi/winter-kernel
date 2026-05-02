<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value does not look like a phone number.
 * Allows: digits, +, spaces, dashes, parentheses. Total length 7–20 chars.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Phone] string $phone  // "+7 (900) 123-45-67" → pass, "abc" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Phone implements Constraint
{
    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return preg_match('/^\+?[\d\s\-\(\)]{7,20}$/', (string) $value) === 1
            ? null
            : 'must be a valid phone number';
    }
}
