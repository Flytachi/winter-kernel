<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid MSISDN (ITU-T E.164 without leading +).
 * Digits only, 7–15 characters.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Msisdn] string $msisdn  // "79001234567" → pass, "+7900..." → fail, "123" → fail
 * ```
 *
 * @link https://winterframe.net/docs/validation Constraints, error shape and messages
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Msisdn implements Constraint
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
        return preg_match('/^\d{7,15}$/', (string) $value) === 1
            ? null
            : ($this->message ?? 'must be a valid MSISDN (7–15 digits, no + prefix)');
    }
}
