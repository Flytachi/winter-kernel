<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is an empty string or contains only whitespace.
 * null is allowed — combine with #[Required] to also reject null.
 *
 * ```
 *   #[NotBlank] string  $title     // "" → fail, "  " → fail, "ok" → pass
 *   #[NotBlank] ?string $slug      // null → pass, "" → fail
 *   #[Required] #[NotBlank] ?string $name  // null → fail, "" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class NotBlank implements Constraint
{
    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return trim((string) $value) === '' ? 'must not be blank' : null;
    }
}
