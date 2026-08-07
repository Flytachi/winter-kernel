<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid email address (RFC 5321 via filter_var).
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Email] string  $email   // "bad" → fail, "user@mail.com" → pass
 *   #[Email] ?string $email   // null → pass, "bad" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Email implements Constraint
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
        return filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false
            ? null
            : ($this->message ?? 'must be a valid email address');
    }
}
