<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid IPv6 address.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Ipv6] string $address  // "::1" → pass, "192.168.1.1" → fail, "bad" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Ipv6 implements Constraint
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
        return filter_var((string) $value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? null
            : ($this->message ?? 'must be a valid IPv6 address');
    }
}
