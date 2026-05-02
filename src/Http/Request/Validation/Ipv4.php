<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid IPv4 address.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Ipv4] string $address  // "192.168.1.1" → pass, "::1" → fail, "bad" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Ipv4 implements Constraint
{
    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return filter_var((string) $value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? null
            : 'must be a valid IPv4 address';
    }
}
