<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid URL (RFC 2396 via filter_var).
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Url] string  $website   // "bad" → fail, "https://example.com" → pass
 *   #[Url] ?string $website   // null → pass, "bad" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Url implements Constraint
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
        return filter_var((string) $value, FILTER_VALIDATE_URL) !== false
            ? null
            : ($this->message ?? 'must be a valid URL');
    }
}
