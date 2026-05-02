<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid IP address (IPv4 or IPv6).
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Ip] string $address  // "192.168.1.1" → pass, "::1" → pass, "bad" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Ip implements Constraint
{
    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return filter_var((string) $value, FILTER_VALIDATE_IP) !== false
            ? null
            : 'must be a valid IP address';
    }
}
