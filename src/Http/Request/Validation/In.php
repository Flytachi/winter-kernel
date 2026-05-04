<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not in the given list. Uses strict comparison (===).
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[In(['active', 'inactive', 'banned'])] string $status
 *   #[In([1, 2, 3])]                        int    $priority
 *   #[In(['yes', 'no'], strict: false)]      string $flag  // loose comparison
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class In implements Constraint
{
    /**
     * @param array $values Allowed values to check against.
     * @param bool  $strict Use strict comparison (===). Defaults to true.
     */
    public function __construct(
        public array $values,
        public bool $strict = true,
    ) {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (in_array($value, $this->values, $this->strict)) {
            return null;
        }
        $allowed = implode(', ', array_map(
            static fn($v) => is_string($v) ? "\"$v\"" : (string) $v,
            $this->values
        ));
        return "must be one of [$allowed]";
    }
}
